<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

final class SecurityHeadersMiddleware implements Middleware
{
    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);
        return new Response($response->body, $response->status, $response->contentType, $response->headers + [
            'Content-Security-Policy' => "default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cache-Control' => 'no-store',
        ]);
    }
}
