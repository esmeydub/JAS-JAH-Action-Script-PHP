<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use Jah\JAS\Security\IdentityProvider;
use Jah\JAS\Security\AuthorizationProvider;
use Jah\JAS\Security\RolePolicy;

final class AuthMiddleware implements Middleware
{
    /** @param array<string,string> $routePermissions */
    public function __construct(private readonly IdentityProvider $auth, private readonly RolePolicy $roles, private readonly array $routePermissions) {}

    public function process(Request $request, callable $next): Response
    {
        $authorization = (string) ($request->headers['authorization'] ?? '');
        $token = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
        $identity = $this->auth->identity($token);
        if ($identity === null) return new Response('JAS_AUTH_REQUIRED', 401, headers: ['WWW-Authenticate' => 'Bearer realm="JAS"']);
        $permission = $this->routePermissions[$request->method . ' ' . $request->path] ?? null;
        $allowed = !is_string($permission)
            || ($this->auth instanceof AuthorizationProvider
                ? $this->auth->allows($token, $permission)
                : $this->roles->allows((array) $identity['roles'], $permission));
        if (!$allowed) return new Response('JAS_PERMISSION_DENIED', 403);
        $attributes = $request->attributes;
        $attributes['identity'] = $identity;
        return $next(new Request($request->method, $request->path, $request->input, $request->headers, $request->requestId, $attributes));
    }
}
