<?php

declare(strict_types=1);

$boot = require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Observability\DiskPressureGuard;
use Jah\JAS\Observability\HealthRegistry;
use Jah\JAS\Queue\PersistentJobQueue;
use Jah\JAS\Telemetry\MetricsRegistry;
use Jah\JAS\Web\OperationalPanelEndpoint;
use Jah\JAS\Web\Request;

$root = (string) $boot['root'];
$dataCore = (string) $boot['config']['paths']['datacore_storage'];
$disk = DiskPressureGuard::fromEnvironment($root);
$health = (new HealthRegistry())
    ->check('php.runtime', static fn(): bool => version_compare(PHP_VERSION, '8.2.0', '>='))
    ->check('runtime.writable', static fn(): bool => is_dir($root . '/runtime') && is_writable($root . '/runtime'))
    ->check('datacore.writable', static fn(): bool => is_dir($dataCore) && is_writable($dataCore))
    ->check('disk.capacity', static fn(): array => $disk->report());
$metrics = new MetricsRegistry($root . '/runtime/telemetry');
$queue = new PersistentJobQueue($root . '/runtime/queue');
$token = (string) (getenv('JAS_OPERATIONS_TOKEN') ?: '');
$node = (string) (getenv('JAS_NODE_ID') ?: 'jas-node');

$endpoint = new OperationalPanelEndpoint(
    $health,
    static function (Request $request, string $permission) use ($token): bool {
        if ($permission !== OperationalPanelEndpoint::PERMISSION || strlen($token) < 32) return false;
        $authorization = (string) ($request->headers['authorization'] ?? '');
        return str_starts_with($authorization, 'Bearer ')
            && hash_equals($token, substr($authorization, 7));
    },
    static fn(): array => $metrics->snapshot(),
    static fn(): array => ['primary' => $queue->stats()],
    $node,
);

try {
    $endpoint->handle(Request::fromGlobals())->send();
} catch (Throwable) {
    \Jah\JAS\Web\SecurityHeadersMiddleware::secure(
        new \Jah\JAS\Web\Response("JAS OPERATIONS: BAD REQUEST\n", 400)
    )->send();
}
