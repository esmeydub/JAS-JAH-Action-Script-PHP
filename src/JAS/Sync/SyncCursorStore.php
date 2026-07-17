<?php

declare(strict_types=1);

namespace Jah\JAS\Sync;

use Jah\DataCore\PhpSerializer;
use RuntimeException;

final class SyncCursorStore
{
    private string $file;
    private string $lock;

    public function __construct(string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('sync_cursor_directory_failed');
        }
        $this->file = rtrim($directory, '/') . '/cursors.state';
        $this->lock = rtrim($directory, '/') . '/cursors.lock';
    }

    public function get(string $peerId, string $stream): int
    {
        return (int) ($this->read()[$peerId][$stream] ?? 0);
    }

    public function advance(string $peerId, string $stream, int $sequence): void
    {
        if ($sequence < 0) {
            throw new RuntimeException('sync_cursor_invalid');
        }
        $this->withLock(function () use ($peerId, $stream, $sequence): void {
            $state = $this->readUnlocked();
            $current = (int) ($state[$peerId][$stream] ?? 0);
            if ($sequence <= $current) {
                return;
            }
            $state[$peerId][$stream] = $sequence;
            $this->writeUnlocked($state);
        });
    }

    private function read(): array
    {
        return $this->withLock(fn(): array => $this->readUnlocked());
    }

    private function readUnlocked(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $raw = file_get_contents($this->file);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = PhpSerializer::decode($raw);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeUnlocked(array $state): void
    {
        $payload = PhpSerializer::encode($state);
        $tmp = $this->file . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $payload, LOCK_EX) !== strlen($payload) || !rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new RuntimeException('sync_cursor_write_failed');
        }
        @chmod($this->file, 0600);
    }

    private function withLock(callable $fn): mixed
    {
        $handle = fopen($this->lock, 'c+b');
        if (!$handle || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('sync_cursor_lock_failed');
        }
        try {
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
