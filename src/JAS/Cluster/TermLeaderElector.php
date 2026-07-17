<?php

declare(strict_types=1);

namespace Jah\JAS\Cluster;

use Jah\DataCore\PhpSerializer;
use RuntimeException;

final class TermLeaderElector
{
    private string $file;
    private string $lock;

    public function __construct(string $directory, private readonly NodeRegistry $registry)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('election_directory_failed');
        }

        $directory = rtrim($directory, '/');
        $this->file = $directory . '/term.jahl';
        $this->lock = $directory . '/term.lock';
    }

    public function elect(): array
    {
        return $this->locked(function (): array {
            $nodes = $this->registry->all(true);
            if ($nodes === []) {
                throw new RuntimeException('no_live_nodes');
            }

            ksort($nodes, SORT_STRING);
            $candidate = (string) array_key_first($nodes);
            $state = $this->state();

            if (($state['leader_id'] ?? null) !== $candidate) {
                $state = [
                    'term' => (int) ($state['term'] ?? 0) + 1,
                    'leader_id' => $candidate,
                    'elected_at' => microtime(true),
                    'members' => array_keys($nodes),
                ];
                $this->write($state);
            }

            return $state;
        });
    }

    public function state(): array
    {
        if (!is_file($this->file)) {
            return ['term' => 0, 'leader_id' => null];
        }

        $state = PhpSerializer::decode(trim((string) file_get_contents($this->file)));
        return is_array($state) ? $state : ['term' => 0, 'leader_id' => null];
    }

    private function write(array $state): void
    {
        $temporary = $this->file . '.tmp.' . bin2hex(random_bytes(4));
        $contents = PhpSerializer::encode($state) . "\n";
        $written = file_put_contents($temporary, $contents, LOCK_EX);
        if ($written !== strlen($contents) || !rename($temporary, $this->file)) {
            @unlink($temporary);
            throw new RuntimeException('election_write_failed');
        }
        @chmod($this->file, 0600);
    }

    private function locked(callable $operation): mixed
    {
        $handle = fopen($this->lock, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('election_lock_failed');
        }

        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
