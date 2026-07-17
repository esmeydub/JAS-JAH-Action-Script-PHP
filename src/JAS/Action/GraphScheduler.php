<?php

declare(strict_types=1);

namespace Jah\JAS\Action;

use Fiber;
use RuntimeException;
use Throwable;

final class GraphScheduler
{
    /** @var callable(string,array,array):mixed */
    private $executor;

    public function __construct(
        callable $executor,
        private readonly int $maxConcurrent = 16,
        private readonly int $maxNodes = 10_000,
        private readonly int $maxTicks = 100_000
    )
    {
        $this->executor = $executor;
        if ($maxConcurrent < 1) throw new RuntimeException('maxConcurrent debe ser mayor que cero');
        if ($maxNodes < 1) throw new RuntimeException('maxNodes debe ser mayor que cero');
        if ($maxTicks < 1) throw new RuntimeException('maxTicks debe ser mayor que cero');
    }

    public function run(ActionGraph $graph): array
    {
        $graph->validate();
        $pending = $graph->nodes();
        if (count($pending) > $this->maxNodes) throw new RuntimeException('graph_node_limit_exceeded');
        $running = [];
        $results = [];
        $failed = [];
        $ticks = 0;

        while ($pending !== [] || $running !== []) {
            if (++$ticks > $this->maxTicks) throw new RuntimeException('graph_tick_limit_exceeded');
            uasort($pending, static function (ActionNode $a, ActionNode $b): int {
                $priority = $b->priority <=> $a->priority;
                return $priority !== 0 ? $priority : strcmp($a->id, $b->id);
            });
            foreach ($pending as $id => $node) {
                if (count($running) >= $this->maxConcurrent) break;
                if (array_intersect($node->dependencies, array_keys($failed)) !== []) {
                    $failed[$id] = ['success' => false, 'error' => 'dependency_failed'];
                    unset($pending[$id]);
                    continue;
                }
                if (array_diff($node->dependencies, array_keys($results)) !== []) continue;

                $dependencyResults = array_intersect_key($results, array_flip($node->dependencies));
                $fiber = new Fiber(function () use ($node, $dependencyResults) {
                    return ($this->executor)($node->action, $node->payload, $dependencyResults);
                });
                $fiber->start();
                $running[$id] = [$fiber, $node];
                unset($pending[$id]);
            }

            if ($running === [] && $pending !== []) {
                throw new RuntimeException('El grafo JAS quedó bloqueado');
            }

            foreach ($running as $id => [$fiber, $node]) {
                try {
                    if (!$fiber->isTerminated() && $fiber->isSuspended()) $fiber->resume();
                    if ($fiber->isTerminated()) {
                        $value = $fiber->getReturn();
                        $result = is_array($value) && array_key_exists('success', $value)
                            ? $value
                            : ['success' => true, 'result' => $value];
                        if (($result['success'] ?? false) === true) $results[$id] = $result;
                        else $failed[$id] = $result;
                        unset($running[$id]);
                    }
                } catch (Throwable $error) {
                    $failed[$id] = ['success' => false, 'error' => $error->getMessage()];
                    unset($running[$id]);
                }
            }
        }

        return ['success' => $failed === [], 'results' => $results, 'failed' => $failed];
    }
}
