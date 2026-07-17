<?php

declare(strict_types=1);

namespace Jah\JAS\Persistence;

use Jah\DataCore\PhpSerializer;
use RuntimeException;

final class EventJournal
{
    private string $file;
    private string $lock;

    public function __construct(string $directory, private readonly string $nodeId = 'local')
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $nodeId)) throw new RuntimeException('event_node_id_invalid');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('event_journal_directory_failed');
        $this->file = rtrim($directory, '/') . '/events.jahl';
        $this->lock = rtrim($directory, '/') . '/events.lock';
    }

    public function append(string $name, int $version, string $requestId, array $payload): array
    {
        if (!preg_match('/^[a-z][a-z0-9_.:-]{2,255}$/', $name)) throw new RuntimeException('event_name_invalid');
        if ($version < 1 || $version > 65_535) throw new RuntimeException('event_version_invalid');
        return $this->locked(function () use ($name, $version, $requestId, $payload): array {
            $events = $this->readUnlocked();
            foreach ($events as $existing) {
                if (($existing['name'] ?? null) === $name && ($existing['version'] ?? null) === $version && ($existing['request_id'] ?? null) === $requestId) {
                    if (($existing['payload'] ?? null) !== $payload) throw new RuntimeException('event_idempotency_mismatch');
                    return $existing;
                }
            }
            $previous = $events === [] ? str_repeat('0', 64) : (string) end($events)['hash'];
            $event = [
                'id' => bin2hex(random_bytes(16)), 'name' => $name, 'version' => $version,
                'request_id' => $requestId, 'node_id' => $this->nodeId,
                'sequence' => count($events) + 1, 'occurred_at' => microtime(true),
                'payload' => $payload, 'previous_hash' => $previous,
            ];
            $event['hash'] = hash('sha256', PhpSerializer::encode($event));
            $line = PhpSerializer::encode($event) . "\n";
            $handle = fopen($this->file, 'ab');
            if ($handle === false) throw new RuntimeException('event_journal_open_failed');
            try {
                if (fwrite($handle, $line) !== strlen($line) || !fflush($handle)) throw new RuntimeException('event_journal_write_failed');
                if (function_exists('fsync')) @fsync($handle);
            } finally { fclose($handle); }
            @chmod($this->file, 0600);
            return $event;
        });
    }

    public function all(): array { return $this->locked(fn(): array => $this->readUnlocked()); }

    public function verify(): bool
    {
        $previous = str_repeat('0', 64);
        foreach ($this->all() as $sequence => $event) {
            if (($event['sequence'] ?? null) !== $sequence + 1 || ($event['previous_hash'] ?? null) !== $previous) return false;
            $expected = $event['hash'] ?? '';
            unset($event['hash']);
            if (!is_string($expected) || !hash_equals($expected, hash('sha256', PhpSerializer::encode($event)))) return false;
            $previous = $expected;
        }
        return true;
    }

    private function readUnlocked(): array
    {
        if (!is_file($this->file)) return [];
        $events = [];
        foreach (file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $event = PhpSerializer::decode($line);
            if (!is_array($event)) throw new RuntimeException('event_journal_corrupt');
            $events[] = $event;
        }
        return $events;
    }

    private function locked(callable $operation): mixed
    {
        $handle = fopen($this->lock, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('event_journal_lock_failed');
        try { return $operation(); } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
