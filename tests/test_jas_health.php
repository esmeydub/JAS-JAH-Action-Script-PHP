<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Observability\HealthRegistry;
use Jah\JAS\Web\OperationalHealthEndpoint;
use Jah\JAS\Web\Request;

$calls = 0;
$healthyRegistry = (new HealthRegistry())
    ->check('datacore.ready', static function () use (&$calls): bool { $calls++; return true; })
    ->check('queue.ready', static function () use (&$calls): array { $calls++; return ['ok' => true, 'secret' => 'hidden']; });
$healthy = new OperationalHealthEndpoint(
    $healthyRegistry,
    static fn(Request $request): bool => ($request->headers['authorization'] ?? '') === 'Bearer valid-health-token',
);

$live = $healthy->handle(new Request('GET', '/health/live'));
if ($live->status !== 200 || $live->body !== "JAS LIVE\n" || $calls !== 0) throw new RuntimeException('health_liveness_dependency_leak');
if (($live->headers['Cache-Control'] ?? null) !== 'no-store' || ($live->headers['X-Content-Type-Options'] ?? null) !== 'nosniff') {
    throw new RuntimeException('health_security_headers_missing');
}

$ready = $healthy->handle(new Request('GET', '/health/ready'));
if ($ready->status !== 200 || $ready->body !== "JAS READY\n" || $calls !== 2
    || str_contains($ready->body, 'datacore') || str_contains($ready->body, 'secret')) {
    throw new RuntimeException('health_readiness_contract_failed');
}

$unauthorized = $healthy->handle(new Request('GET', '/health'));
if ($unauthorized->status !== 401 || str_contains($unauthorized->body, 'datacore.ready')) throw new RuntimeException('health_details_exposed');
$details = $healthy->handle(new Request('GET', '/health', headers: ['authorization' => 'Bearer valid-health-token']));
if ($details->status !== 200 || !str_contains($details->body, 'datacore.ready OK') || str_contains($details->body, 'hidden')) {
    throw new RuntimeException('health_authorized_details_failed');
}

$failedRegistry = (new HealthRegistry())
    ->check('datacore.ready', static fn(): bool => false)
    ->check('queue.ready', static function (): bool { throw new RuntimeException('internal-secret-path'); });
$failed = new OperationalHealthEndpoint($failedRegistry, static fn(): bool => true, 17);
$notReady = $failed->handle(new Request('GET', '/health/ready'));
if ($notReady->status !== 503 || $notReady->body !== "JAS NOT READY\n"
    || ($notReady->headers['Retry-After'] ?? null) !== '17' || str_contains($notReady->body, 'internal-secret-path')) {
    throw new RuntimeException('health_failure_not_contained');
}
$failedDetails = $failed->handle(new Request('GET', '/health'));
if ($failedDetails->status !== 503 || !str_contains($failedDetails->body, 'queue.ready FAIL')
    || str_contains($failedDetails->body, 'internal-secret-path')) throw new RuntimeException('health_failure_details_invalid');

$closed = new OperationalHealthEndpoint($healthyRegistry, static function (): bool { throw new RuntimeException('authorization-failed'); });
if ($closed->handle(new Request('GET', '/health'))->status !== 401) throw new RuntimeException('health_authorization_not_closed');
if ($healthy->handle(new Request('POST', '/health/live'))->status !== 405) throw new RuntimeException('health_method_accepted');
if ($healthy->handle(new Request('GET', '/other'))->status !== 404) throw new RuntimeException('health_unknown_path_accepted');

echo "JAS OPERATIONAL HEALTH: PASS\n";
