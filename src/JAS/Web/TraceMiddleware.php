<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use Jah\JAS\Observability\JahLogger;

final class TraceMiddleware implements Middleware
{
    public function __construct(private readonly JahLogger $logger) {}
    public function process(Request $request, callable $next): Response
    {
        $requestId = $request->requestId ?? bin2hex(random_bytes(16));
        $request = new Request($request->method, $request->path, $request->input, $request->headers, $requestId, $request->attributes);
        $started = hrtime(true);
        $response = $next($request);
        $duration = (hrtime(true) - $started) / 1_000_000;
        $this->logger->log('info', 'http.request', [
            'request_id' => $requestId, 'method' => $request->method, 'path' => $request->path,
            'status' => $response->status, 'duration_ms' => $duration,
            'user_id' => $request->attributes['identity']['id'] ?? null,
        ]);
        return new Response($response->body, $response->status, $response->contentType, $response->headers + ['X-JAS-Request-ID' => $requestId]);
    }
}
