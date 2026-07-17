<?php

declare(strict_types=1);

use JAS\ReferencePortal\PortalKernel;
use Jah\JAS\Web\Request;
use Jah\JAS\Web\Response;
use Jah\JAS\Web\Router;
use Jah\JAS\Web\SecurityHeadersMiddleware;

$jasRoot = realpath((string) getenv('JAS_ROOT'));
if ($jasRoot === false || !is_file($jasRoot . '/app/bootstrap.php')) {
    SecurityHeadersMiddleware::secure(new Response('Servicio no disponible.', 503))->send();
}
require_once $jasRoot . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/PortalKernel.php';

$masterKey = (string) getenv('PORTAL_MASTER_KEY');
$pepper = (string) getenv('PORTAL_IDENTITY_PEPPER');
if (strlen($masterKey) < 32 || strlen($pepper) < 32) {
    SecurityHeadersMiddleware::secure(new Response('Servicio no disponible.', 503))->send();
}

try {
    $request = Request::fromGlobals(65_536);
    if ($request->method === 'GET' && $request->path === '/health') {
        SecurityHeadersMiddleware::secure(new Response("status=ready\n"))->send();
    }
    $kernel = new PortalKernel(dirname(__DIR__) . '/runtime', $masterKey, $pepper);
    $render = static function (array $values): Response {
        $lines = [];
        $walk = static function (array $items, string $prefix = '') use (&$walk, &$lines): void {
            foreach ($items as $key => $value) {
                $name = $prefix === '' ? (string) $key : $prefix . '.' . $key;
                if (is_array($value)) { $walk($value, $name); continue; }
                if (is_bool($value)) $value = $value ? 'true' : 'false';
                $lines[] = $name . '=' . str_replace(["\r", "\n"], ' ', (string) $value);
            }
        };
        $walk($values);
        return new Response(implode("\n", $lines) . "\n", 200, 'text/plain; charset=utf-8', ['Cache-Control' => 'no-store']);
    };

    if ($request->path === '/login') {
        $router = (new Router($kernel->anonymousRuntime()))
            ->middleware(new SecurityHeadersMiddleware())
            ->route('POST', '/login', 'identidad.authenticate', $render, 'portal.login');
        $router->dispatch($request)->send();
    }

    $authorization = trim((string) ($request->headers['authorization'] ?? ''));
    if (preg_match('/^Bearer ([A-Za-z0-9+\/=._:-]{32,2048})$/', $authorization, $match) !== 1) {
        SecurityHeadersMiddleware::secure(new Response('No autorizado.', 401))->send();
    }
    $runtime = $kernel->runtimeForToken($match[1]);
    $router = (new Router($runtime))
        ->middleware(new SecurityHeadersMiddleware())
        ->route('GET', '/feed', 'feed.read', $render, 'portal.feed')
        ->route('POST', '/publicaciones', 'publicacion.publish', $render, 'portal.publish')
        ->route('POST', '/mensajes', 'mensaje.send', $render, 'portal.message')
        ->route('POST', '/moderacion', 'moderacion.review', $render, 'portal.moderation')
        ->route('GET', '/notificaciones', 'notificacion.list', $render, 'portal.notifications')
        ->route('GET', '/auditoria', 'auditoria.verify', $render, 'portal.audit');
    $router->dispatch($request)->send();
} catch (Throwable) {
    SecurityHeadersMiddleware::secure(new Response('Solicitud rechazada.', 401))->send();
}
