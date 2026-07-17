<?php

declare(strict_types=1);

namespace Jah\JAS\Observability;

use RuntimeException;
use Throwable;

final class HealthRegistry
{
    /** @var array<string,callable> */
    private array $checks = [];
    public function check(string $name, callable $check): self
    {
        if (!preg_match('/^[a-z][a-z0-9_.:-]{2,127}$/', $name) || isset($this->checks[$name])) throw new RuntimeException('health_check_invalid');
        $this->checks[$name] = $check; return $this;
    }
    public function run(): array
    {
        $results = []; $healthy = true;
        foreach ($this->checks as $name => $check) {
            $started = hrtime(true);
            try {
                $value = $check(); $ok = $value === true || (is_array($value) && ($value['ok'] ?? false) === true);
                $results[$name] = ['ok' => $ok, 'duration_ms' => (hrtime(true) - $started) / 1_000_000];
            } catch (Throwable) {
                $ok = false; $results[$name] = ['ok' => false, 'duration_ms' => (hrtime(true) - $started) / 1_000_000];
            }
            $healthy = $healthy && $ok;
        }
        return ['ok' => $healthy, 'checks' => $results, 'checked_at' => microtime(true)];
    }
}
