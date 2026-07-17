<?php

declare(strict_types=1);

namespace Jah\JAS\Observability;

final class AggregatedMetrics
{
    public static function combine(array $nodeSnapshots): array
    {
        $result = [
            'nodes' => count($nodeSnapshots),
            'counters' => [],
            'gauges' => [],
            'timings' => [],
            'node_status' => [],
            'generated_at' => microtime(true),
        ];

        foreach ($nodeSnapshots as $node => $snapshot) {
            $result['node_status'][$node] = ['updated_at' => $snapshot['updated_at'] ?? null];
            foreach ((array) ($snapshot['counters'] ?? []) as $key => $value) {
                $result['counters'][$key] = ($result['counters'][$key] ?? 0) + $value;
            }
            foreach ((array) ($snapshot['gauges'] ?? []) as $key => $value) {
                $result['gauges'][$key] = ($result['gauges'][$key] ?? 0) + $value;
            }
            foreach ((array) ($snapshot['timings'] ?? []) as $key => $metrics) {
                $aggregate = $result['timings'][$key] ?? [
                    'count' => 0,
                    'total_ms' => 0.0,
                    'min_ms' => null,
                    'max_ms' => null,
                ];
                $aggregate['count'] += (int) ($metrics['count'] ?? 0);
                $aggregate['total_ms'] += (float) ($metrics['total_ms'] ?? 0);
                $minimum = $metrics['min_ms'] ?? null;
                $maximum = $metrics['max_ms'] ?? null;
                if ($minimum !== null) {
                    $aggregate['min_ms'] = $aggregate['min_ms'] === null
                        ? $minimum
                        : min($aggregate['min_ms'], $minimum);
                }
                if ($maximum !== null) {
                    $aggregate['max_ms'] = $aggregate['max_ms'] === null
                        ? $maximum
                        : max($aggregate['max_ms'], $maximum);
                }
                $aggregate['avg_ms'] = $aggregate['count'] > 0
                    ? $aggregate['total_ms'] / $aggregate['count']
                    : 0;
                $result['timings'][$key] = $aggregate;
            }
        }
        return $result;
    }
}
