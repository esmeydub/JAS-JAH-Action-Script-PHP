<?php

declare(strict_types=1);

namespace Jah\JAS\Replication;

use Jah\DataCore\PhpSerializer;
use RuntimeException;

final class ReplicatedQueueLog
{
    private string $file;
    private string $lock;

    public function __construct(string $directory, private readonly string $nodeId)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('replication_directory_failed');
        }
        $this->file = rtrim($directory, '/') . '/replication.journal';
        $this->lock = rtrim($directory, '/') . '/replication.lock';
    }

    public function append(string $stream, string $eventId, array $event): array
    {
        return $this->locked(function () use ($stream, $eventId, $event): array {
            foreach ($this->events($stream, 0, $this->nodeId) as $row) {
                if (($row['event_id'] ?? null) === $eventId) {
                    return $row;
                }
            }

            $originRows = $this->events($stream, 0, $this->nodeId);
            $last = $originRows === [] ? null : $originRows[array_key_last($originRows)];
            $row = [
                'node_id' => $this->nodeId,
                'stream' => $stream,
                'seq' => $last === null ? 1 : ((int) $last['seq'] + 1),
                'event_id' => $eventId,
                'event' => $event,
                'at' => microtime(true),
                'prev_hash' => $last['hash'] ?? str_repeat('0', 64),
            ];
            $row['hash'] = hash('sha256', PhpSerializer::encode($row));
            $this->appendRow($row);
            return $row;
        });
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function events(?string $stream = null, int $afterSeq = 0, ?string $originNode = null): array
    {
        $out = [];
        if (!is_file($this->file)) {
            return $out;
        }

        foreach (file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = PhpSerializer::decode($line);
            if (!is_array($row)) {
                continue;
            }
            if ($stream !== null && ($row['stream'] ?? null) !== $stream) {
                continue;
            }
            if ($originNode !== null && ($row['node_id'] ?? null) !== $originNode) {
                continue;
            }
            if ((int) ($row['seq'] ?? 0) <= $afterSeq) {
                continue;
            }
            $out[] = $row;
        }

        usort($out, static fn(array $a, array $b): int => [
            (string) ($a['node_id'] ?? ''),
            (int) ($a['seq'] ?? 0),
        ] <=> [
            (string) ($b['node_id'] ?? ''),
            (int) ($b['seq'] ?? 0),
        ]);

        return $out;
    }

    public function lastSequence(string $stream, string $originNode): int
    {
        $rows = $this->events($stream, 0, $originNode);
        if ($rows === []) {
            return 0;
        }
        return (int) $rows[array_key_last($rows)]['seq'];
    }

    public function import(array $rows): int
    {
        return $this->locked(function () use ($rows): int {
            $added = 0;
            usort($rows, static fn(mixed $a, mixed $b): int => is_array($a) && is_array($b)
                ? [(string)($a['node_id'] ?? ''), (int)($a['seq'] ?? 0)] <=> [(string)($b['node_id'] ?? ''), (int)($b['seq'] ?? 0)]
                : 0);

            foreach ($rows as $row) {
                if (!is_array($row) || !isset($row['node_id'], $row['stream'], $row['seq'], $row['event_id'], $row['hash'])) {
                    throw new RuntimeException('replication_row_invalid');
                }

                $origin = (string) $row['node_id'];
                $stream = (string) $row['stream'];
                $seq = (int) $row['seq'];
                if ($origin === '' || $stream === '' || $seq < 1) {
                    throw new RuntimeException('replication_row_invalid');
                }

                foreach ($this->events($stream, 0, $origin) as $existing) {
                    if (($existing['event_id'] ?? null) === $row['event_id']) {
                        continue 2;
                    }
                }

                $copy = $row;
                $hash = (string) $copy['hash'];
                unset($copy['hash']);
                if (!hash_equals($hash, hash('sha256', PhpSerializer::encode($copy)))) {
                    throw new RuntimeException('replication_hash_invalid');
                }

                $lastRows = $this->events($stream, 0, $origin);
                $last = $lastRows === [] ? null : $lastRows[array_key_last($lastRows)];
                $expectedSeq = $last === null ? 1 : ((int) $last['seq'] + 1);
                $expectedPrev = $last['hash'] ?? str_repeat('0', 64);
                if ($seq !== $expectedSeq || !hash_equals($expectedPrev, (string) ($row['prev_hash'] ?? ''))) {
                    throw new RuntimeException('replication_chain_gap');
                }

                $this->appendRow($row);
                $added++;
            }
            return $added;
        });
    }

    public function verify(string $stream, ?string $originNode = null): bool
    {
        $origins = $originNode !== null ? [$originNode] : array_values(array_unique(array_map(
            static fn(array $row): string => (string) ($row['node_id'] ?? ''),
            $this->events($stream)
        )));

        foreach ($origins as $origin) {
            if ($origin === '') {
                return false;
            }
            $prev = str_repeat('0', 64);
            $seq = 0;
            foreach ($this->events($stream, 0, $origin) as $row) {
                $copy = $row;
                $hash = (string) ($copy['hash'] ?? '');
                unset($copy['hash']);
                if ((int) ($row['seq'] ?? 0) !== ++$seq
                    || !hash_equals($prev, (string) ($row['prev_hash'] ?? ''))
                    || !hash_equals($hash, hash('sha256', PhpSerializer::encode($copy)))) {
                    return false;
                }
                $prev = $hash;
            }
        }
        return true;
    }

    private function appendRow(array $row): void
    {
        $line = PhpSerializer::encode($row) . "\n";
        if (file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new RuntimeException('replication_write_failed');
        }
        @chmod($this->file, 0600);
    }

    private function locked(callable $fn): mixed
    {
        $handle = fopen($this->lock, 'c+b');
        if (!$handle || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('replication_lock_failed');
        }
        try {
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
