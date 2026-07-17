<?php

declare(strict_types=1);

namespace Jah\JAS\Consensus;

use Jah\DataCore\PhpSerializer;
use RuntimeException;

final class FencingTokenStore
{
    private string $file;
    private string $lock;

    public function __construct(string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('fencing_directory_failed');
        }

        $directory = rtrim($directory, '/');
        $this->file = $directory . '/fencing.jahl';
        $this->lock = $directory . '/fencing.lock';
    }

    public function issue(string $leaderId, int $term): array
    {
        return $this->locked(function () use ($leaderId, $term): array {
            $state = $this->read();
            $token = max(
                (int) ($state['token'] ?? 0) + 1,
                (int) (microtime(true) * 1_000_000),
            );
            $state = [
                'leader_id' => $leaderId,
                'term' => $term,
                'token' => $token,
                'issued_at' => microtime(true),
            ];
            $this->write($state);
            return $state;
        });
    }

    public function current(): array
    {
        return $this->locked(fn(): array => $this->read());
    }

    public function assertValid(string $leaderId, int $term, int $token): void
    {
        $state = $this->current();
        $valid = ($state['leader_id'] ?? null) === $leaderId
            && (int) ($state['term'] ?? -1) === $term
            && (int) ($state['token'] ?? -1) === $token;

        if (!$valid) {
            throw new RuntimeException('stale_fencing_token');
        }
    }

    private function read(): array
    {
        $empty = ['leader_id' => null, 'term' => 0, 'token' => 0];
        if (!is_file($this->file)) {
            return $empty;
        }

        $state = PhpSerializer::decode(trim((string) file_get_contents($this->file)));
        return is_array($state) ? $state : $empty;
    }

    private function write(array $state): void
    {
        $temporary = $this->file . '.tmp.' . bin2hex(random_bytes(4));
        $contents = PhpSerializer::encode($state) . "\n";
        $written = file_put_contents($temporary, $contents, LOCK_EX);
        if ($written !== strlen($contents) || !rename($temporary, $this->file)) {
            @unlink($temporary);
            throw new RuntimeException('fencing_write_failed');
        }
        @chmod($this->file, 0600);
    }

    private function locked(callable $operation): mixed
    {
        $handle = fopen($this->lock, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('fencing_lock_failed');
        }

        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
