<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Observability\HealthRegistry;
use Jah\JAS\Web\OperationalPanelEndpoint;
use Jah\JAS\Web\Request;

$calls = 0;
$endpoint = new OperationalPanelEndpoint(
    (new HealthRegistry())->check('datacore.ready', static fn(): bool => true),
    static fn(Request $request, string $permission): bool => $permission === 'operations.view'
        && ($request->headers['authorization'] ?? '') === 'Bearer institutional-session',
    static function () use (&$calls): array {
        $calls++;
        return [
            'counters' => ['queue.completed' => 12, '<script>secret' => 99],
            'gauges' => ['workers.active' => 3, 'invalid' => INF],
            'timings' => ['request.duration' => ['avg_ms' => 4.25], 'bad' => ['avg_ms' => NAN]],
            'secret' => 'do-not-render',
        ];
    },
    static function () use (&$calls): array {
        $calls++;
        return ['primary' => [
            'states' => ['queued' => 4, 'leased' => 2, 'completed' => 8, 'failed' => 1, 'cancelled' => 0],
            'dead_letters' => 1, 'capacity' => 100,
            'partitions' => ['social' => ['active' => 6, 'max_active' => 80, 'saturated' => false]],
            'payload' => 'must-not-render',
        ]];
    },
    'government-node-1',
);

$unauthorized = $endpoint->handle(new Request('GET', '/operations'));
if ($unauthorized->status !== 401 || $calls !== 0 || str_contains($unauthorized->body, 'datacore')) {
    throw new RuntimeException('operations_authorization_boundary_failed');
}
$response = $endpoint->handle(new Request('GET', '/operations', headers: ['authorization' => 'Bearer institutional-session']));
if ($response->status !== 200 || $response->contentType !== 'text/html; charset=utf-8' || $calls !== 2) {
    throw new RuntimeException('operations_panel_response_failed');
}
foreach (['government-node-1', 'datacore.ready', 'queue.completed', 'workers.active', 'request.duration', 'primary', 'social'] as $visible) {
    if (!str_contains($response->body, $visible)) throw new RuntimeException('operations_expected_data_missing');
}
foreach (['<script>', 'do-not-render', 'must-not-render', 'INF', 'NAN'] as $forbidden) {
    if (str_contains($response->body, $forbidden)) throw new RuntimeException('operations_untrusted_data_exposed');
}
if (($response->headers['Cache-Control'] ?? null) !== 'no-store'
    || !str_contains((string) ($response->headers['Content-Security-Policy'] ?? ''), "object-src 'none'")) {
    throw new RuntimeException('operations_security_headers_missing');
}
if ($endpoint->handle(new Request('POST', '/operations'))->status !== 405
    || $endpoint->handle(new Request('GET', '/unknown'))->status !== 404) {
    throw new RuntimeException('operations_route_contract_failed');
}

$degraded = new OperationalPanelEndpoint(
    (new HealthRegistry())->check('queue.ready', static fn(): bool => false),
    static fn(): bool => true,
    static function (): array { throw new RuntimeException('/secret/metrics/path'); },
    static fn(): array => [],
);
$failed = $degraded->handle(new Request('GET', '/operations'));
if ($failed->status !== 503 || ($failed->headers['Retry-After'] ?? null) !== '5'
    || str_contains($failed->body, '/secret/metrics/path') || !str_contains($failed->body, 'unavailable')) {
    throw new RuntimeException('operations_failure_containment_failed');
}

$closed = new OperationalPanelEndpoint(
    new HealthRegistry(),
    static function (): bool { throw new RuntimeException('identity-storage-failed'); },
    static fn(): array => [],
    static fn(): array => [],
);
if ($closed->handle(new Request('GET', '/operations'))->status !== 401) {
    throw new RuntimeException('operations_authorization_not_closed');
}

echo "JAS OPERATIONAL PANEL: PASS\n";
