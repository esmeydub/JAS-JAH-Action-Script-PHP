<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use Closure;
use Jah\JAS\Observability\HealthRegistry;
use Throwable;

/** Read-only, server-rendered operational view with an explicit authorization boundary. */
final class OperationalPanelEndpoint
{
    public const PERMISSION = 'operations.view';

    private readonly Closure $authorize;
    private readonly Closure $metrics;
    private readonly Closure $queues;

    /**
     * @param callable(Request,string):bool $authorize
     * @param callable():array $metrics
     * @param callable():array $queues
     */
    public function __construct(
        private readonly HealthRegistry $health,
        callable $authorize,
        callable $metrics,
        callable $queues,
        private readonly string $nodeName = 'jas-node',
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/', $nodeName) !== 1) {
            throw new \InvalidArgumentException('operations_node_invalid');
        }
        $this->authorize = Closure::fromCallable($authorize);
        $this->metrics = Closure::fromCallable($metrics);
        $this->queues = Closure::fromCallable($queues);
    }

    public function handle(Request $request): Response
    {
        if (!in_array($request->path, ['/operations', '/operations.php'], true)) {
            return $this->plain('JAS OPERATIONS: NOT FOUND', 404);
        }
        if ($request->method !== 'GET') {
            return $this->plain('JAS OPERATIONS: METHOD NOT ALLOWED', 405, ['Allow' => 'GET']);
        }
        try {
            $authorized = ($this->authorize)($request, self::PERMISSION) === true;
        } catch (Throwable) {
            $authorized = false;
        }
        if (!$authorized) {
            return $this->plain('JAS OPERATIONS: UNAUTHORIZED', 401, [
                'WWW-Authenticate' => 'Bearer realm="JAS Operations"',
            ]);
        }

        $health = $this->health->run();
        [$metrics, $metricsAvailable] = $this->safeSource($this->metrics, 'metrics');
        [$queues, $queuesAvailable] = $this->safeSource($this->queues, 'queues');
        $available = $metricsAvailable && $queuesAvailable;
        $status = ($health['ok'] && $available) ? 200 : 503;
        $response = Response::html($this->page($health, $metrics, $queues, $available), $status);
        if ($status === 503) $response = $response->withHeaders(['Retry-After' => '5']);
        return SecurityHeadersMiddleware::secure($response);
    }

    /** @return array{0:array,1:bool} */
    private function safeSource(Closure $source, string $kind): array
    {
        try {
            $value = $source();
            if (!is_array($value)) return [[], false];
            return [$kind === 'metrics' ? $this->metricsSnapshot($value) : $this->queueSnapshots($value), true];
        } catch (Throwable) {
            return [[], false];
        }
    }

    private function page(array $health, array $metrics, array $queues, bool $sourcesAvailable): SafeHtml
    {
        $healthRows = [];
        foreach (array_slice($health['checks'], 0, 128, true) as $name => $check) {
            if (!$this->validName($name) || !is_array($check)) continue;
            $healthRows[] = [$name, ($check['ok'] ?? false) === true ? 'OK' : 'FAIL', $this->milliseconds($check['duration_ms'] ?? 0)];
        }
        $metricRows = [];
        foreach (['counters', 'gauges'] as $group) {
            foreach ((array) ($metrics[$group] ?? []) as $name => $value) {
                $metricRows[] = [$group, $name, $this->number($value)];
            }
        }
        foreach ((array) ($metrics['timings'] ?? []) as $name => $timing) {
            $metricRows[] = ['timings', $name, $this->milliseconds($timing['avg_ms'] ?? 0)];
        }
        $queueRows = [];
        $partitionRows = [];
        foreach ($queues as $name => $queue) {
            $states = (array) ($queue['states'] ?? []);
            $queueRows[] = [
                $name,
                (string) ($states['queued'] ?? 0),
                (string) ($states['leased'] ?? 0),
                (string) ($queue['dead_letters'] ?? 0),
                (string) ($queue['capacity'] ?? 0),
            ];
            foreach ((array) ($queue['partitions'] ?? []) as $partition => $state) {
                $partitionRows[] = [
                    $name, $partition, (string) ($state['active'] ?? 0),
                    (string) ($state['max_active'] ?? 0),
                    ($state['saturated'] ?? false) === true ? 'SATURATED' : 'OK',
                ];
            }
        }

        $overall = ($health['ok'] && $sourcesAvailable) ? 'READY' : 'ATTENTION';
        $content = Html::element('div', ['class' => 'jas-operations'],
            Html::element('header', [],
                Html::element('p', ['class' => 'jas-kicker'], 'JAS — JAH Action Script PHP'),
                Html::element('h1', [], 'Operational panel'),
                Html::element('p', [], 'Node ', Html::element('strong', [], $this->nodeName), ' · ', $overall),
            ),
            !$sourcesAvailable ? Html::element('p', ['role' => 'alert'], 'One or more operational sources are unavailable.') : null,
            $this->section('Readiness', ['Check', 'State', 'Duration'], $healthRows),
            $this->section('Metrics', ['Kind', 'Metric', 'Value'], $metricRows),
            $this->section('Queues', ['Queue', 'Queued', 'Leased', 'Dead letters', 'Capacity'], $queueRows),
            $this->section('Queue isolation', ['Queue', 'Partition', 'Active', 'Limit', 'State'], $partitionRows),
            Html::element('footer', [], Html::element('p', [], 'Read-only view · no operational commands · no client scripts')),
        );

        return Html::fragment(
            new SafeHtml('<!doctype html>'),
            Html::element('html', ['lang' => 'en'],
                Html::element('head', [],
                    Html::element('meta', ['charset' => 'utf-8']),
                    Html::element('meta', ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1']),
                    Html::element('title', [], 'JAS Operational Panel'),
                    Html::element('link', ['rel' => 'stylesheet', 'href' => '/operations.css']),
                ),
                Html::element('body', [], $content),
            ),
        );
    }

    private function section(string $title, array $headings, array $rows): SafeHtml
    {
        $header = [];
        foreach ($headings as $heading) $header[] = Html::element('th', ['scope' => 'col'], $heading);
        $body = [];
        foreach (array_slice($rows, 0, 512) as $row) {
            $cells = [];
            foreach ($row as $cell) $cells[] = Html::element('td', [], $cell);
            $body[] = Html::element('tr', [], $cells);
        }
        if ($body === []) $body[] = Html::element('tr', [], Html::element('td', ['colspan' => (string) count($headings)], 'No data'));
        return Html::element('section', [], Html::element('h2', [], $title),
            Html::element('div', ['class' => 'jas-table-scroll'], Html::element('table', [],
                Html::element('thead', [], Html::element('tr', [], $header)), Html::element('tbody', [], $body),
            )),
        );
    }

    private function metricsSnapshot(array $snapshot): array
    {
        $result = ['counters' => [], 'gauges' => [], 'timings' => []];
        foreach (['counters', 'gauges'] as $group) {
            foreach (array_slice((array) ($snapshot[$group] ?? []), 0, 256, true) as $name => $value) {
                if ($this->validName($name) && $this->finite($value)) $result[$group][$name] = $value;
            }
        }
        foreach (array_slice((array) ($snapshot['timings'] ?? []), 0, 256, true) as $name => $timing) {
            if (!$this->validName($name) || !is_array($timing) || !$this->finite($timing['avg_ms'] ?? null)) continue;
            $result['timings'][$name] = ['avg_ms' => (float) $timing['avg_ms']];
        }
        return $result;
    }

    private function queueSnapshots(array $snapshots): array
    {
        $result = [];
        foreach (array_slice($snapshots, 0, 64, true) as $name => $snapshot) {
            if (!$this->validName($name) || !is_array($snapshot)) continue;
            $states = [];
            foreach (['queued', 'leased', 'completed', 'failed', 'cancelled'] as $state) {
                $states[$state] = $this->safeInteger($snapshot['states'][$state] ?? 0);
            }
            $partitions = [];
            foreach (array_slice((array) ($snapshot['partitions'] ?? []), 0, 128, true) as $partition => $item) {
                if (!$this->validName($partition) || !is_array($item)) continue;
                $partitions[$partition] = [
                    'active' => $this->safeInteger($item['active'] ?? 0),
                    'max_active' => $this->safeInteger($item['max_active'] ?? 0),
                    'saturated' => ($item['saturated'] ?? false) === true,
                ];
            }
            $result[$name] = [
                'states' => $states,
                'dead_letters' => $this->safeInteger($snapshot['dead_letters'] ?? 0),
                'capacity' => $this->safeInteger($snapshot['capacity'] ?? 0),
                'partitions' => $partitions,
            ];
        }
        return $result;
    }

    private function validName(mixed $name): bool
    {
        return is_string($name) && preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,127}$/', $name) === 1;
    }

    private function finite(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private function safeInteger(mixed $value): int
    {
        return is_int($value) && $value >= 0 ? min($value, PHP_INT_MAX) : 0;
    }

    private function number(mixed $value): string
    {
        return number_format((float) $value, is_int($value) ? 0 : 3, '.', '');
    }

    private function milliseconds(mixed $value): string
    {
        return number_format($this->finite($value) && (float) $value >= 0 ? (float) $value : 0, 3, '.', '') . ' ms';
    }

    private function plain(string $body, int $status, array $headers = []): Response
    {
        return SecurityHeadersMiddleware::secure(new Response($body . "\n", $status, headers: $headers));
    }
}
