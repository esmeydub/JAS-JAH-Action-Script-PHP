<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use RuntimeException;

final class RateLimitMiddleware implements Middleware
{
    /** @param callable(Request):string $keyResolver */
    public function __construct(
        private readonly RateLimitStore $store,
        private readonly mixed $keyResolver,
        private readonly int $limit = 60,
        private readonly int $windowSeconds = 60
    ) {
        if (!is_callable($keyResolver)) throw new RuntimeException('rate_limit_resolver_invalid');
    }

    public function process(Request $request, callable $next): Response
    {
        $key = ($this->keyResolver)($request);
        if (!is_string($key) || $key === '') return new Response('JAS_CLIENT_ID_REQUIRED', 401);
        $state = $this->store->consume($key . ':' . $request->method . ':' . $request->path, $this->limit, $this->windowSeconds);
        if (!$state['allowed']) {
            return new Response('JAS_RATE_LIMITED', 429, headers: ['Retry-After' => (string) $state['retry_after']]);
        }
        $response = $next($request);
        return $response->withHeaders([
            'X-RateLimit-Limit' => (string) $this->limit,
            'X-RateLimit-Remaining' => (string) $state['remaining'],
        ]);
    }
}
