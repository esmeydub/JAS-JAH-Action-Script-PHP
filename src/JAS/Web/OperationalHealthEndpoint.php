<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use Closure;
use Jah\JAS\Observability\HealthRegistry;
use Throwable;

/** HTTP boundary for orchestrator probes; it never executes application actions. */
final class OperationalHealthEndpoint
{
    private readonly Closure $authorizeDetails;

    /** @param callable(Request):bool $authorizeDetails */
    public function __construct(
        private readonly HealthRegistry $readiness,
        callable $authorizeDetails,
        private readonly int $retryAfterSeconds = 5,
    ) {
        if ($retryAfterSeconds < 1 || $retryAfterSeconds > 300) {
            throw new \InvalidArgumentException('health_retry_after_invalid');
        }
        $this->authorizeDetails = Closure::fromCallable($authorizeDetails);
    }

    public function handle(Request $request): Response
    {
        if (!in_array($request->path, ['/health/live', '/health/ready', '/health'], true)) {
            return $this->response('JAS HEALTH: NOT FOUND', 404);
        }
        if ($request->method !== 'GET') {
            return $this->response('JAS HEALTH: METHOD NOT ALLOWED', 405, ['Allow' => 'GET']);
        }
        if ($request->path === '/health/live') {
            return $this->response('JAS LIVE', 200);
        }

        if ($request->path === '/health') {
            try {
                $authorized = ($this->authorizeDetails)($request) === true;
            } catch (Throwable) {
                $authorized = false;
            }
            if (!$authorized) return $this->response('JAS HEALTH: UNAUTHORIZED', 401);
        }

        $report = $this->readiness->run();
        $status = $report['ok'] ? 200 : 503;
        $headers = $status === 503 ? ['Retry-After' => (string) $this->retryAfterSeconds] : [];
        if ($request->path === '/health/ready') {
            return $this->response($report['ok'] ? 'JAS READY' : 'JAS NOT READY', $status, $headers);
        }

        $lines = [$report['ok'] ? 'JAS HEALTH: READY' : 'JAS HEALTH: NOT READY'];
        foreach ($report['checks'] as $name => $result) {
            $lines[] = $name . ' ' . ($result['ok'] ? 'OK' : 'FAIL') . ' ' . number_format((float) $result['duration_ms'], 3, '.', '') . 'ms';
        }
        return $this->response(implode("\n", $lines), $status, $headers);
    }

    private function response(string $body, int $status, array $headers = []): Response
    {
        return SecurityHeadersMiddleware::secure(new Response($body . "\n", $status, headers: $headers));
    }
}
