<?php

declare(strict_types=1);

namespace Jah\JAS\Observability;

use Closure;
use Jah\DataCore\WriteAdmission;
use RuntimeException;

final class DiskPressureGuard implements WriteAdmission
{
    public const NORMAL = 'normal';
    public const WARNING = 'warning';
    public const CRITICAL = 'critical';
    public const EMERGENCY = 'emergency';

    private readonly Closure $probe;
    private readonly ?Closure $alert;
    private ?string $lastLevel = null;

    /** @param null|callable(array):void $alert */
    public static function fromEnvironment(string $path, ?callable $alert = null): self
    {
        $totalValue = disk_total_space($path);
        if ($totalValue === false || $totalValue < 1) throw new RuntimeException('disk_pressure_probe_failed');
        $total = (int) $totalValue;
        $warningDefault = min(2_147_483_648, max(67_108_864, (int) floor($total * 0.20)));
        $criticalDefault = min(1_073_741_824, max(33_554_432, (int) floor($total * 0.10)));
        $emergencyDefault = min(268_435_456, max(16_777_216, (int) floor($total * 0.03)));
        $warning = (int) (getenv('JAS_DISK_WARNING_BYTES') ?: $warningDefault);
        $critical = (int) (getenv('JAS_DISK_CRITICAL_BYTES') ?: $criticalDefault);
        $emergency = (int) (getenv('JAS_DISK_EMERGENCY_BYTES') ?: $emergencyDefault);
        return new self($path, $warning, $critical, $emergency, alert: $alert);
    }

    /**
     * @param null|callable(string):array{total:int|float,free:int|float} $probe
     * @param null|callable(array{previous:?string,current:string,free_bytes:int,total_bytes:int,free_percent:float,at:float}):void $alert
     */
    public function __construct(
        private readonly string $path,
        private readonly int $warningBytes = 2_147_483_648,
        private readonly int $criticalBytes = 1_073_741_824,
        private readonly int $emergencyBytes = 268_435_456,
        ?callable $probe = null,
        ?callable $alert = null,
    ) {
        if ($path === '' || $emergencyBytes < 1 || $criticalBytes <= $emergencyBytes || $warningBytes <= $criticalBytes) {
            throw new RuntimeException('disk_pressure_configuration_invalid');
        }
        $this->probe = $probe === null
            ? static function (string $target): array {
                $total = disk_total_space($target);
                $free = disk_free_space($target);
                if ($total === false || $free === false) throw new RuntimeException('disk_pressure_probe_failed');
                return ['total' => $total, 'free' => $free];
            }
            : Closure::fromCallable($probe);
        $this->alert = $alert === null ? null : Closure::fromCallable($alert);
    }

    /** @return array{ok:bool,level:string,free_bytes:int,total_bytes:int,free_percent:float,accepting_regular_writes:bool,accepting_essential_writes:bool} */
    public function report(): array
    {
        $sample = ($this->probe)($this->path);
        $total = (int) ($sample['total'] ?? 0);
        $free = (int) ($sample['free'] ?? -1);
        if ($total < 1 || $free < 0 || $free > $total) throw new RuntimeException('disk_pressure_probe_invalid');
        $level = $this->level($free);
        $percent = round(($free / $total) * 100, 3);
        $this->transition($level, $free, $total, $percent);
        return [
            'ok' => $level === self::NORMAL || $level === self::WARNING,
            'level' => $level,
            'free_bytes' => $free,
            'total_bytes' => $total,
            'free_percent' => $percent,
            'accepting_regular_writes' => !in_array($level, [self::CRITICAL, self::EMERGENCY], true),
            'accepting_essential_writes' => $level !== self::EMERGENCY,
        ];
    }

    public function assertWritable(string $operation, int $estimatedBytes, bool $essential = false): void
    {
        if (preg_match('/^[a-z][a-z0-9_.:-]{2,127}$/', $operation) !== 1 || $estimatedBytes < 0 || $estimatedBytes > 1_073_741_824) {
            throw new RuntimeException('disk_pressure_write_request_invalid');
        }
        $report = $this->report();
        $projectedLevel = $this->level(max(0, $report['free_bytes'] - $estimatedBytes));
        if ($report['level'] === self::EMERGENCY || $projectedLevel === self::EMERGENCY) {
            throw new RuntimeException('disk_pressure_emergency');
        }
        if (!$essential && ($report['level'] === self::CRITICAL || $projectedLevel === self::CRITICAL)) {
            throw new RuntimeException('disk_pressure_write_rejected');
        }
    }

    private function level(int $free): string
    {
        if ($free <= $this->emergencyBytes) return self::EMERGENCY;
        if ($free <= $this->criticalBytes) return self::CRITICAL;
        if ($free <= $this->warningBytes) return self::WARNING;
        return self::NORMAL;
    }

    private function transition(string $level, int $free, int $total, float $percent): void
    {
        $previous = $this->lastLevel;
        if ($previous === $level) return;
        $this->lastLevel = $level;
        if ($this->alert === null || ($previous === null && $level === self::NORMAL)) return;
        ($this->alert)([
            'previous' => $previous,
            'current' => $level,
            'free_bytes' => $free,
            'total_bytes' => $total,
            'free_percent' => $percent,
            'at' => microtime(true),
        ]);
    }
}
