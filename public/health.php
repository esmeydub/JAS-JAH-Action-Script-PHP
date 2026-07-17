<?php

declare(strict_types=1);

$boot = require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Observability\HealthRegistry;
use Jah\JAS\Web\OperationalHealthEndpoint;
use Jah\JAS\Web\Request;

$root = (string) $boot['root'];
$dataCore = (string) $boot['config']['paths']['datacore_storage'];
$minimumFreeBytes = max(16_777_216, (int) (getenv('JAS_HEALTH_MIN_FREE_BYTES') ?: 268_435_456));
$registry = (new HealthRegistry())
    ->check('php.runtime', static fn(): bool => version_compare(PHP_VERSION, '8.2.0', '>='))
    ->check('runtime.writable', static fn(): bool => is_dir($root . '/runtime') && is_writable($root . '/runtime'))
    ->check('datacore.writable', static fn(): bool => is_dir($dataCore) && is_writable($dataCore))
    ->check('disk.capacity', static function () use ($root, $minimumFreeBytes): bool {
        $free = disk_free_space($root);
        return is_float($free) && $free >= $minimumFreeBytes;
    });

$token = (string) (getenv('JAS_HEALTH_TOKEN') ?: '');
$endpoint = new OperationalHealthEndpoint($registry, static function (Request $request) use ($token): bool {
    if (strlen($token) < 32) return false;
    $authorization = (string) ($request->headers['authorization'] ?? '');
    return str_starts_with($authorization, 'Bearer ')
        && hash_equals($token, substr($authorization, 7));
});

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$rawPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/health'), PHP_URL_PATH) ?: '/health');
$path = match ($rawPath) {
    '/health.php/live' => '/health/live',
    '/health.php/ready' => '/health/ready',
    '/health.php', '/health.php/' => '/health',
    default => $rawPath,
};
$headers = [];
foreach ($_SERVER as $name => $value) {
    if (str_starts_with($name, 'HTTP_') && is_scalar($value)) {
        $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = (string) $value;
    }
}

try {
    $endpoint->handle(new Request($method, $path, headers: $headers))->send();
} catch (Throwable) {
    \Jah\JAS\Web\SecurityHeadersMiddleware::secure(
        new \Jah\JAS\Web\Response("JAS HEALTH: BAD REQUEST\n", 400)
    )->send();
}
