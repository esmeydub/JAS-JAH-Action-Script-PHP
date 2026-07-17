<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use RuntimeException;

final class CsrfMiddleware implements Middleware
{
    /** @param callable():string $tokenProvider */
    public function __construct(private readonly mixed $tokenProvider, private readonly string $field = '_csrf')
    {
        if (!is_callable($tokenProvider)) throw new RuntimeException('csrf_token_provider_invalid');
    }

    public function process(Request $request, callable $next): Response
    {
        if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $expected = ($this->tokenProvider)();
            $provided = (string) ($request->input[$this->field] ?? $request->headers['x-jas-csrf-token'] ?? '');
            if (!is_string($expected) || strlen($expected) < 32 || $provided === '' || !hash_equals($expected, $provided)) {
                return new Response('JAS_CSRF_REJECTED', 403);
            }
            $input = $request->input;
            unset($input[$this->field]);
            $request = new Request($request->method, $request->path, $input, $request->headers, $request->requestId);
        }
        return $next($request);
    }
}
