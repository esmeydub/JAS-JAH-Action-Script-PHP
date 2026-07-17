<?php

declare(strict_types=1);

namespace Jah\JAS\Balance;

use Jah\JAS\Cluster\NodeRegistry;
use RuntimeException;

final class NodeLoadBalancer
{
    public function __construct(private readonly NodeRegistry $registry)
    {
    }

    public function select(string $capability, array $exclude = []): array
    {
        $best = null;
        $bestScore = INF;
        foreach ($this->registry->all(true) as $node) {
            if (in_array($node['id'], $exclude, true)) continue;
            if (!$this->supports((array) ($node['capabilities'] ?? []), $capability)) continue;

            $metadata = (array) ($node['metadata'] ?? []);
            $score = (float) ($metadata['cpu_percent'] ?? 0)
                + (float) ($metadata['active_jobs'] ?? 0) * 10
                + (float) ($metadata['latency_ms'] ?? 0) / 10;
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $node;
            }
        }
        if ($best === null) {
            throw new RuntimeException('no_eligible_node');
        }
        $best['score'] = $bestScore;
        return $best;
    }

    private function supports(array $capabilities, string $required): bool
    {
        foreach ($capabilities as $capability) {
            if ($capability === '*' || $capability === $required) return true;
            if (str_ends_with($capability, '*') && str_starts_with($required, substr($capability, 0, -1))) {
                return true;
            }
        }
        return false;
    }
}
