<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use Jah\JAS\Runtime\GovernedRuntime;
use RuntimeException;
use Throwable;

final class Router
{
    /** @var array<string,array{action:string,render:callable,template:string,regex:string,params:array,name:?string}> */
    private array $routes = [];
    /** @var list<Middleware> */
    private array $middleware = [];

    public function __construct(private readonly GovernedRuntime $runtime) {}

    public function route(string $method, string $path, string $action, callable $render, ?string $name = null): self
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) throw new RuntimeException('http_method_invalid');
        $validTemplate = preg_match(
            '#^/(?:[A-Za-z0-9_-]+|\{[a-z_][a-z0-9_]*\})(?:/(?:[A-Za-z0-9_-]+|\{[a-z_][a-z0-9_]*\}))*$#i',
            $path,
        );
        if (!$validTemplate) {
            throw new RuntimeException('http_route_template_invalid');
        }
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
        ];
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
        $pipeline = array_reduce(
            array_reverse($this->middleware),
            static fn(callable $next, Middleware $middleware): callable => static fn(Request $current): Response => $middleware->process($current, $next),
            $core
        );
        return $pipeline($request);
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
        if ($route === null) return new Response('JAS_NOT_FOUND', 404);
        try {
            foreach ($pathInput as $key => $value) {
                if (array_key_exists($key, $request->input) && (string) $request->input[$key] !== $value) return new Response('JAS_ROUTE_INPUT_CONFLICT', 400);
            }
            $input = $pathInput + $request->input;
            $request = new Request($request->method, $request->path, $input, $request->headers, $request->requestId, $request->attributes);
            $result = $this->runtime->execute($route['action'], $input, $request->requestId);
            $response = ($route['render'])($result['result'], $result, $request);
            if (!$response instanceof Response) throw new RuntimeException('http_renderer_response_invalid');
            return $response;
        } catch (\InvalidArgumentException) {
            return new Response('JAS_REQUEST_INVALID', 422);
        } catch (Throwable) {
            return new Response('JAS_INTERNAL_ERROR', 500);
        }
    }
}
