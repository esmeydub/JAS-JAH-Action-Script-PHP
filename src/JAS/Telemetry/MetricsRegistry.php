<?php

declare(strict_types=1);

namespace Jah\JAS\Telemetry;

use Jah\DataCore\PhpSerializer;
use RuntimeException;

final class MetricsRegistry
{
    private string $file;
    private string $lock;

    public function __construct(string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('No se pudo crear telemetría');
        $this->file = rtrim($directory, '/') . '/metrics.jahl';
        $this->lock = rtrim($directory, '/') . '/metrics.lock';
    }

    public function increment(string $name, int|float $by = 1): void
    {
        $this->validateName($name);
        if (!is_finite((float) $by)) throw new RuntimeException('metric_value_invalid');
        $this->mutate(function (array &$data) use ($name, $by): void { $data['counters'][$name] = ($data['counters'][$name] ?? 0) + $by; });
    }

    public function gauge(string $name, int|float $value): void
    {
        $this->validateName($name);
        if (!is_finite((float) $value)) throw new RuntimeException('metric_value_invalid');
        $this->mutate(function (array &$data) use ($name, $value): void { $data['gauges'][$name] = $value; });
    }

    public function observe(string $name, float $milliseconds): void
    {
        $this->validateName($name);
        if (!is_finite($milliseconds) || $milliseconds < 0) throw new RuntimeException('metric_observation_invalid');
        $this->mutate(function (array &$data) use ($name, $milliseconds): void {
            $metric = $data['timings'][$name] ?? ['count'=>0,'total_ms'=>0.0,'min_ms'=>null,'max_ms'=>null];
            $metric['count']++;
            $metric['total_ms'] += $milliseconds;
            $metric['min_ms'] = $metric['min_ms'] === null ? $milliseconds : min($metric['min_ms'], $milliseconds);
            $metric['max_ms'] = $metric['max_ms'] === null ? $milliseconds : max($metric['max_ms'], $milliseconds);
            $data['timings'][$name] = $metric;
        });
    }

    public function snapshot(): array
    {
        return $this->withLock(fn(): array => $this->readUnlocked());
    }

    private function mutate(callable $callback): void
    {
        $this->withLock(function () use ($callback): void {
            $data = $this->readUnlocked();
            $callback($data);
            $data['updated_at'] = microtime(true);
            $temp = $this->file . '.tmp.' . bin2hex(random_bytes(4));
            $encoded = PhpSerializer::encode($data) . "\n";
            $handle = fopen($temp, 'xb');
            if ($handle === false) throw new RuntimeException('No se pudo crear telemetría temporal');
            try {
                if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) throw new RuntimeException('No se pudo escribir telemetría');
                if (function_exists('fsync')) @fsync($handle);
            } finally { fclose($handle); }
            if (!rename($temp, $this->file)) { @unlink($temp); throw new RuntimeException('No se pudo publicar telemetría'); }
            @chmod($this->file, 0600);
        });
    }

    private function readUnlocked(): array
    {
        if (!is_file($this->file)) return ['counters'=>[],'gauges'=>[],'timings'=>[],'updated_at'=>microtime(true)];
        $value = PhpSerializer::decode(trim((string)file_get_contents($this->file)));
        return is_array($value) ? $value : ['counters'=>[],'gauges'=>[],'timings'=>[]];
    }

    private function withLock(callable $callback): mixed
    {
        $h = fopen($this->lock, 'c+b');
        if ($h === false) throw new RuntimeException('No se pudo abrir lock de telemetría');
        try { if (!flock($h, LOCK_EX)) throw new RuntimeException('No se pudo bloquear telemetría'); return $callback(); }
        finally { flock($h, LOCK_UN); fclose($h); }
    }

    private function validateName(string $name): void
    {
        if (strlen($name) < 1 || strlen($name) > 128 || preg_match('/^[A-Za-z][A-Za-z0-9_.:-]*$/', $name) !== 1) {
            throw new RuntimeException('metric_name_invalid');
        }
    }
}
