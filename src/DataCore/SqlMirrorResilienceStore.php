<?php

declare(strict_types=1);

namespace Jah\DataCore;

use RuntimeException;

final class SqlMirrorResilienceStore
{
    private string $file;

    public function __construct(
        string $directory,
        private readonly int $failureThreshold = 3,
        private readonly int $quarantineAttempts = 5,
        private readonly int $cooldownSeconds = 60,
    ) {
        if ($failureThreshold < 1 || $quarantineAttempts < 1 || $cooldownSeconds < 1) {
            throw new RuntimeException('sql_mirror_resilience_policy_invalid');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('sql_mirror_resilience_directory_failed');
        }
        $this->file = rtrim($directory, '/') . '/resilience.jahl';
    }

    public function failure(string $operationId, string $errorCode): array
    {
        $state = $this->state();
        $attempts = (int) ($state['attempts'][$operationId] ?? 0) + 1;
        $consecutive = (int) ($state['consecutive_failures'] ?? 0) + 1;
        $quarantined = $attempts >= $this->quarantineAttempts;
        $openedUntil = $consecutive >= $this->failureThreshold
            ? time() + $this->cooldownSeconds
            : (int) ($state['opened_until'] ?? 0);
        $this->append([
            'type' => 'FAILURE',
            'operation_id' => $operationId,
            'attempts' => $attempts,
            'consecutive_failures' => $consecutive,
            'quarantined' => $quarantined,
            'opened_until' => $openedUntil,
            'error_code' => hash('sha256', $errorCode),
            'at' => microtime(true),
        ]);
        return ['attempts' => $attempts, 'quarantined' => $quarantined, 'opened_until' => $openedUntil];
    }

    public function success(string $operationId): void
    {
        $this->append([
            'type' => 'SUCCESS',
            'operation_id' => $operationId,
            'at' => microtime(true),
        ]);
    }

    public function isOpen(): bool
    {
        return (int) ($this->state()['opened_until'] ?? 0) > time();
    }

    public function isQuarantined(string $operationId): bool
    {
        return isset($this->state()['quarantined'][$operationId]);
    }

    public function state(): array
    {
        $state = [
            'attempts' => [],
            'quarantined' => [],
            'consecutive_failures' => 0,
            'opened_until' => 0,
        ];
        if (!is_file($this->file)) return $state;
        foreach (file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = PhpSerializer::decode($line);
            if (!is_array($entry)) throw new RuntimeException('sql_mirror_resilience_corrupt');
            $id = (string) ($entry['operation_id'] ?? '');
            if (($entry['type'] ?? null) === 'FAILURE') {
                $state['attempts'][$id] = (int) ($entry['attempts'] ?? 0);
                $state['consecutive_failures'] = (int) ($entry['consecutive_failures'] ?? 0);
                $state['opened_until'] = (int) ($entry['opened_until'] ?? 0);
                if (($entry['quarantined'] ?? false) === true) $state['quarantined'][$id] = true;
            } elseif (($entry['type'] ?? null) === 'SUCCESS') {
                $state['consecutive_failures'] = 0;
                $state['opened_until'] = 0;
                unset($state['attempts'][$id]);
            }
        }
        return $state;
    }

    private function append(array $entry): void
    {
        $line = PhpSerializer::encode($entry) . "\n";
        if (file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new RuntimeException('sql_mirror_resilience_write_failed');
        }
        @chmod($this->file, 0600);
    }
}
