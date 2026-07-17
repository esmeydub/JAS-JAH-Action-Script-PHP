<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use Jah\JAS\Runtime\GovernedRuntime;
use RuntimeException;
use Throwable;

final class Router
{
    /** @var array<string,array{method:string,action:string,render:callable,template:string,regex:string,params:list<string>,name:?string,middleware:list<Middleware>}> */
    private array $routes = [];
    /** @var list<Middleware> */
    private array $middleware = [];
    /** @var list<array{prefix:string,middleware:list<Middleware>}> */
    private array $groups = [];

    public function __construct(private readonly GovernedRuntime $runtime, private readonly ?Translator $translator = null) {}

    /** @param list<Middleware> $middleware */
    public function route(string $method, string $path, string $action, callable $render, ?string $name = null, array $middleware = []): self
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) throw new RuntimeException('http_method_invalid');
        $validTemplate = preg_match(
            '#^(?:/|/(?:[A-Za-z0-9_-]+|\{[a-z_][a-z0-9_]*\})(?:/(?:[A-Za-z0-9_-]+|\{[a-z_][a-z0-9_]*\}))*)$#i',
            $path,
        );
        if (!$validTemplate) {
            throw new RuntimeException('http_route_template_invalid');
        }
        $path = $this->groupPrefix() . ($path === '/' ? '' : $path);
        if ($path === '') $path = '/';
        $middleware = [...$this->groupMiddleware(), ...$this->validateMiddleware($middleware)];
        if ($name !== null && !preg_match('/^[a-z][a-z0-9_.-]{2,127}$/', $name)) throw new RuntimeException('http_route_name_invalid');
        $key = $method . ' ' . $path;
        if (isset($this->routes[$key])) throw new RuntimeException('http_route_already_defined');
        foreach ($this->routes as $route) if ($name !== null && $route['name'] === $name) throw new RuntimeException('http_route_name_duplicated');
        preg_match_all('/\{([a-z_][a-z0-9_]*)\}/i', $path, $matches);
        $params = $matches[1] ?? [];
        if (count($params) !== count(array_unique($params))) throw new RuntimeException('http_route_parameter_duplicated');
        $regex = preg_replace('/\\\{[a-z_][a-z0-9_]*\\\}/i', '([^/]+)', preg_quote($path, '#')) ?? '';
        $this->routes[$key] = [
            'method' => $method,
            'action' => $action,
            'render' => $render,
            'template' => $path,
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'name' => $name,
            'middleware' => $middleware,
        ];
        return $this;
    }

    /**
     * @param list<Middleware> $middleware
     * @param callable(self):void $routes
     */
    public function group(string $prefix, array $middleware, callable $routes): self
    {
        if ($prefix !== '/' && preg_match('#^/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$#', $prefix) !== 1) {
            throw new RuntimeException('http_group_prefix_invalid');
        }
        $this->groups[] = [
            'prefix' => $prefix === '/' ? '' : $prefix,
            'middleware' => $this->validateMiddleware($middleware),
        ];
        try {
            $routes($this);
        } finally {
            array_pop($this->groups);
        }
        return $this;
    }

    public function url(string $name, array $parameters = [], array $query = []): string
    {
        $route = null;
        foreach ($this->routes as $candidate) if ($candidate['name'] === $name) $route = $candidate;
        if ($route === null) throw new RuntimeException('http_route_name_not_found');
        $url = $route['template'];
        foreach ($route['params'] as $parameter) {
            if (!array_key_exists($parameter, $parameters)) throw new RuntimeException('http_route_parameter_missing');
            $value = (string) $parameters[$parameter];
            if ($value === '' || str_contains($value, '/') || str_contains($value, '..')) throw new RuntimeException('http_route_parameter_invalid');
            $url = str_replace('{' . $parameter . '}', rawurlencode($value), $url);
            unset($parameters[$parameter]);
        }
        if ($parameters !== []) throw new RuntimeException('http_route_parameter_unknown');
        if ($query !== []) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return $url;
    }

    public function middleware(Middleware $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    public function dispatch(Request $request): Response
    {
        $core = fn(Request $current): Response => $this->dispatchRoute($current);
        return $this->pipeline($this->middleware, $core)($request);
    }

    private function dispatchRoute(Request $request): Response
    {
        $route = $this->routes[$request->method . ' ' . $request->path] ?? null;
        $pathInput = [];
        if ($route === null) {
            foreach ($this->routes as $candidate) {
                if (($candidate['method'] ?? null) !== $request->method) continue;
                if (preg_match($candidate['regex'], $request->path, $matches) !== 1) continue;
                array_shift($matches);
                foreach ($candidate['params'] as $index => $parameter) $pathInput[$parameter] = rawurldecode((string) ($matches[$index] ?? ''));
                $route = $candidate; break;
            }
        }
        if ($route === null) return Response::error(404, $request->requestId, translator: $this->translator);
        try {
            foreach ($pathInput as $key => $value) {
                if (array_key_exists($key, $request->input) && (string) $request->input[$key] !== $value) return Response::error(400, $request->requestId, translator: $this->translator);
            }
            $input = $pathInput + $request->input;
            $attributes = $request->attributes + [
                'route_name' => $route['name'],
                'route_template' => $route['template'],
                'route_action' => $route['action'],
            ];
            $request = new Request($request->method, $request->path, $input, $request->headers, $request->requestId, $attributes);
            $execute = function (Request $current) use ($route): Response {
                $result = $this->runtime->execute($route['action'], $current->input, $current->requestId);
                $response = ($route['render'])($result['result'], $result, $current);
                if (!$response instanceof Response) throw new RuntimeException('http_renderer_response_invalid');
                return $response;
            };
            return $this->pipeline($route['middleware'], $execute)($request);
        } catch (\InvalidArgumentException) {
            return Response::error(422, $request->requestId, translator: $this->translator);
        } catch (Throwable) {
            return Response::error(500, $request->requestId, translator: $this->translator);
        }
    }

    /** @param list<Middleware> $middleware */
    private function pipeline(array $middleware, callable $core): callable
    {
        return array_reduce(
            array_reverse($middleware),
            static fn(callable $next, Middleware $item): callable => static fn(Request $current): Response => $item->process($current, $next),
            $core
        );
    }

    private function groupPrefix(): string
    {
        return implode('', array_column($this->groups, 'prefix'));
    }

    /** @return list<Middleware> */
    private function groupMiddleware(): array
    {
        $middleware = [];
        foreach ($this->groups as $group) $middleware = [...$middleware, ...$group['middleware']];
        return $middleware;
    }

    /** @return list<Middleware> */
    private function validateMiddleware(array $middleware): array
    {
        if (!array_is_list($middleware)) throw new RuntimeException('http_middleware_list_invalid');
        foreach ($middleware as $item) {
            if (!$item instanceof Middleware) throw new RuntimeException('http_middleware_invalid');
        }
        return $middleware;
    }
}
