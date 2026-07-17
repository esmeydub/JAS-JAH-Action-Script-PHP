<?php

declare(strict_types=1);

namespace Jah\JAS\Cluster;

use Jah\DataCore\PhpSerializer;
use RuntimeException;

final class NodeRegistry
{
    private string $file;
    private string $lock;

    public function __construct(string $directory, private readonly int $ttlSeconds = 30)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('node_registry_directory_failed');
        }

        $directory = rtrim($directory, '/');
        $this->file = $directory . '/nodes.journal';
        $this->lock = $directory . '/nodes.lock';
    }
    public function heartbeat(NodeIdentity $node, array $metadata=[]): void
    {
        $this->append([
            'type' => 'HEARTBEAT',
            'id' => $node->id,
            'endpoint' => $node->endpoint,
            'capabilities' => $node->capabilities,
            'public_key' => base64_encode($node->publicKey),
            'metadata' => $metadata,
            'at' => time(),
        ]);
    }

    public function remove(string $id): void
    {
        $this->append(['type' => 'REMOVE', 'id' => $id, 'at' => time()]);
    }
    public function all(bool $aliveOnly=true): array
    {
        $nodes = [];
        if (!is_file($this->file)) {
            return [];
        }

        foreach (file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $event = PhpSerializer::decode($line);
            if (!is_array($event) || !isset($event['id'])) {
                continue;
            }
            if (($event['type'] ?? '') === 'REMOVE') {
                unset($nodes[$event['id']]);
                continue;
            }
            if (($event['type'] ?? '') === 'HEARTBEAT') {
                $nodes[$event['id']] = $event;
            }
        }

        if ($aliveOnly) {
            $threshold = time() - $this->ttlSeconds;
            $nodes = array_filter($nodes, fn(array $node): bool => (int) $node['at'] >= $threshold);
        }
        ksort($nodes);
        return $nodes;
    }

    public function get(string $id, bool $aliveOnly = true): ?array
    {
        return $this->all($aliveOnly)[$id] ?? null;
    }
    public function compact(): void
    {
        $this->withLock(function (): void {
            $nodes = $this->all(false);
            $temporary = $this->file . '.tmp.' . bin2hex(random_bytes(4));
            $handle = fopen($temporary, 'xb');
            if ($handle === false) {
                throw new RuntimeException('node_registry_compact_failed');
            }

            try {
                foreach ($nodes as $node) {
                    $line = PhpSerializer::encode($node) . "\n";
                    if (fwrite($handle, $line) !== strlen($line)) {
                        throw new RuntimeException('node_registry_compact_failed');
                    }
                }
                if (!fflush($handle)) {
                    throw new RuntimeException('node_registry_compact_failed');
                }
                if (function_exists('fsync') && !fsync($handle)) {
                    throw new RuntimeException('node_registry_compact_failed');
                }
            } finally {
                fclose($handle);
            }

            if (!rename($temporary, $this->file)) {
                @unlink($temporary);
                throw new RuntimeException('node_registry_compact_failed');
            }
            @chmod($this->file, 0600);
        });
    }

    private function append(array $event): void
    {
        $this->withLock(function () use ($event): void {
            $line = PhpSerializer::encode($event) . "\n";
            if (file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
                throw new RuntimeException('node_registry_write_failed');
            }
            @chmod($this->file, 0600);
        });
    }

    private function withLock(callable $operation): mixed
    {
        $handle = fopen($this->lock, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('node_registry_lock_failed');
        }

        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
