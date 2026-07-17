<?php

declare(strict_types=1);

namespace Jah;

use Generator;
use RuntimeException;
use Throwable;

/**
 * Bounded PHP process runner with an explicit sequential fallback.
 */
final class JasAsyncActions
{
    private array $workers = [];

    public function __construct(private int $maxWorkers = 4)
    {
        if ($maxWorkers < 1) {
            throw new RuntimeException('maxWorkers must be at least 1');
        }
    }

    public function addWorker(callable $task): void
    {
        $this->workers[] = $task;
    }

    public function runAll(): array
    {
        if ($this->workers === []) {
            return [];
        }

        if ($this->maxWorkers === 1 || !function_exists('pcntl_fork') || !function_exists('pcntl_wait')) {
            return $this->runSequentially();
        }

        return $this->runInProcesses();
    }

    public function executionMode(): string
    {
        return $this->maxWorkers > 1 && function_exists('pcntl_fork') && function_exists('pcntl_wait')
            ? 'processes'
            : 'sequential';
    }

    public function stream(Generator $gen): Generator
    {
        foreach ($gen as $key => $value) {
            yield $key => $value;
        }
    }

    private function runSequentially(): array
    {
        $results = [];
        foreach ($this->workers as $index => $worker) {
            $results[$index] = $worker();
        }
        return $results;
    }

    private function runInProcesses(): array
    {
        $results = [];
        $errors = [];
        $running = [];
        $next = 0;
        $total = count($this->workers);

        while ($next < $total || $running !== []) {
            while ($next < $total && count($running) < $this->maxWorkers) {
                $path = tempnam(sys_get_temp_dir(), 'jah-async-');
                if ($path === false) {
                    throw new RuntimeException('Could not create async result channel');
                }

                $index = $next++;
                $pid = pcntl_fork();
                if ($pid === -1) {
                    @unlink($path);
                    throw new RuntimeException('Could not create PHP worker process');
                }

                if ($pid === 0) {
                    $this->runChild($index, $path);
                }

                $running[$pid] = [$index, $path];
            }

            $pid = pcntl_wait($status);
            if ($pid <= 0 || !isset($running[$pid])) {
                continue;
            }

            [$index, $path] = $running[$pid];
            unset($running[$pid]);
            try {
                $results[$index] = $this->readChildResult($path, $status);
            } catch (Throwable $error) {
                $errors[$index] = $error;
            }
        }

        if ($errors !== []) {
            ksort($errors);
            throw reset($errors);
        }
        ksort($results);
        return $results;
    }

    private function runChild(int $index, string $path): never
    {
        try {
            $envelope = ['ok' => true, 'value' => ($this->workers[$index])()];
            $payload = serialize($envelope);
        } catch (Throwable $error) {
            $envelope = ['ok' => false, 'error' => $error->getMessage()];
            $payload = serialize($envelope);
        }

        $written = file_put_contents($path, $payload, LOCK_EX);
        exit($written === strlen($payload) ? 0 : 1);
    }

    private function readChildResult(string $path, int $status): mixed
    {
        try {
            $payload = file_get_contents($path);
        } finally {
            @unlink($path);
        }

        $envelope = is_string($payload)
            ? @unserialize($payload, ['allowed_classes' => false])
            : false;

        if (!is_array($envelope) || !array_key_exists('ok', $envelope)) {
            throw new RuntimeException('PHP worker ended without a valid result');
        }
        if ($envelope['ok'] !== true) {
            throw new RuntimeException((string) ($envelope['error'] ?? 'PHP worker failed'));
        }
        if (function_exists('pcntl_wifexited') && (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0)) {
            throw new RuntimeException('PHP worker exited abnormally');
        }

        return $envelope['value'] ?? null;
    }
}
