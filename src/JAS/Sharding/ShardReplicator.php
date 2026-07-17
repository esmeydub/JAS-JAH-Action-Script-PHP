<?php

declare(strict_types=1);

namespace Jah\JAS\Sharding;

use Jah\DataCore\PhpSerializer;
use Jah\JAS\Replication\ReplicatedQueueLog;
use RuntimeException;

final class ShardReplicator
{
    public function __construct(
        private readonly string $directory,
        private readonly ReplicatedQueueLog $log,
    ) {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('shard_directory_failed');
        }
    }

    public function put(
        string $collection,
        int $shard,
        string $id,
        array $document,
        array $fencing,
    ): array {
        $event = [
            'op' => 'PUT',
            'collection' => $collection,
            'shard' => $shard,
            'id' => $id,
            'document' => $document,
            'fencing' => $fencing,
            'at' => microtime(true),
        ];
        $this->append($this->path($collection, $shard), $event);
        $this->replicate($collection, $shard, $id, $fencing, $event);
        return $event;
    }

    public function delete(string $collection, int $shard, string $id, array $fencing): array
    {
        $event = [
            'op' => 'DELETE',
            'collection' => $collection,
            'shard' => $shard,
            'id' => $id,
            'fencing' => $fencing,
            'at' => microtime(true),
        ];
        $this->append($this->path($collection, $shard), $event);
        $this->replicate($collection, $shard, 'del' . $id, $fencing, $event);
        return $event;
    }

    public function latest(string $collection, int $shard): array
    {
        $state = [];
        $path = $this->path($collection, $shard);
        if (!is_file($path)) {
            return [];
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $event = PhpSerializer::decode($line);
            if (!is_array($event) || !isset($event['id'])) {
                continue;
            }
            if (($event['op'] ?? '') === 'DELETE') {
                unset($state[$event['id']]);
            } else {
                $state[$event['id']] = $event['document'] ?? [];
            }
        }
        return $state;
    }

    public function importEvents(array $rows): int
    {
        $imported = 0;
        foreach ($rows as $row) {
            $event = $row['event'] ?? null;
            if (!is_array($event) || !isset($event['collection'], $event['shard'], $event['id'])) {
                continue;
            }
            $this->append($this->path((string) $event['collection'], (int) $event['shard']), $event);
            $imported++;
        }
        return $imported;
    }

    private function replicate(
        string $collection,
        int $shard,
        string $id,
        array $fencing,
        array $event,
    ): void {
        $this->log->append(
            'shard:' . $collection . ':' . $shard,
            hash('sha256', $id . PhpSerializer::encode($fencing)),
            $event,
        );
    }

    private function path(string $collection, int $shard): string
    {
        $safeCollection = preg_replace('/[^A-Za-z0-9._-]/', '_', $collection);
        $directory = rtrim($this->directory, '/') . '/' . $safeCollection;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('shard_collection_directory_failed');
        }
        return $directory . '/shard-' . str_pad((string) $shard, 4, '0', STR_PAD_LEFT) . '.journal';
    }

    private function append(string $path, array $event): void
    {
        $line = PhpSerializer::encode($event) . "\n";
        if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new RuntimeException('shard_write_failed');
        }
        @chmod($path, 0600);
    }
}
