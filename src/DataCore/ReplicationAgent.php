<?php

declare(strict_types=1);

namespace Jah\DataCore;

use RuntimeException;

/**
 * Signed append-only local replication.
 *
 * JAS pure-PHP mode forbids arbitrary outbound nodes. Replicas are therefore
 * explicit local directories that receive the same verifiable event chain.
 */
final class ReplicationAgent
{
    private array $nodes = [];
    private array $stats = [
        'attempted' => 0,
        'sent' => 0,
        'failed' => 0,
        'nodes' => [],
    ];
    private string $signingKey;

    public function __construct(private string $basePath, ?string $signingKey = null)
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->signingKey = $signingKey
            ?? (string) ($_ENV['JAH_REPLICATION_KEY'] ?? getenv('JAH_REPLICATION_KEY') ?: '');

        if ($this->signingKey === '') {
            throw new RuntimeException('JAH_REPLICATION_KEY is required for signed replication');
        }
        $this->ensureDirectory($this->basePath);
    }

    public function addNode(string $path): void
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('~^[a-z][a-z0-9+.-]*://~i', $path)) {
            throw new RuntimeException('Replication nodes must be local filesystem directories');
        }

        $normalized = rtrim($path, DIRECTORY_SEPARATOR);
        $this->ensureDirectory($normalized);
        if ($normalized !== $this->basePath && !in_array($normalized, $this->nodes, true)) {
            $primaryLog = $this->basePath . DIRECTORY_SEPARATOR . 'replication.log';
            $replicaLog = $normalized . DIRECTORY_SEPARATOR . 'replication.log';
            if (is_file($primaryLog) && !is_file($replicaLog) && !copy($primaryLog, $replicaLog)) {
                throw new RuntimeException("Could not initialize replication node: {$normalized}");
            }
            if (is_file($replicaLog) && !$this->verifyLog($normalized)) {
                throw new RuntimeException("Replication node has an invalid event chain: {$normalized}");
            }
            $this->nodes[] = $normalized;
        }
    }

    public function replicate(array $event): bool
    {
        $lock = fopen($this->basePath . DIRECTORY_SEPARATOR . '.replication.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Could not acquire replication chain lock');
        }

        try {
            $signed = $this->signEvent($event);
            $this->stats['attempted']++;
            $primaryStored = $this->appendEvent($this->basePath, $signed);
            $this->stats[$primaryStored ? 'sent' : 'failed']++;
            $this->stats['nodes'][$this->basePath] = $primaryStored ? 'stored' : 'failed';
            if (!$primaryStored) {
                return false;
            }

            $allStored = true;
            foreach ($this->nodes as $target) {
                $this->stats['attempted']++;
                $stored = $this->appendOrResyncReplica($target, $signed);
                $this->stats[$stored ? 'sent' : 'failed']++;
                $this->stats['nodes'][$target] = $stored ? 'stored' : 'failed';
                $allStored = $allStored && $stored;
            }

            return $allStored;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function verifyLog(?string $path = null): bool
    {
        $log = ($path === null ? $this->basePath : rtrim($path, DIRECTORY_SEPARATOR))
            . DIRECTORY_SEPARATOR . 'replication.log';
        if (!is_file($log)) {
            return false;
        }

        $handle = fopen($log, 'rb');
        if ($handle === false) {
            return false;
        }

        $previous = '';
        $valid = true;
        try {
            while (($line = fgets($handle)) !== false) {
                $event = PhpSerializer::decode(rtrim($line, "\r\n"));
                if (!is_array($event) || !$this->verifyEvent($event, $previous)) {
                    $valid = false;
                    break;
                }
                $previous = (string) $event['hash'];
            }
        } finally {
            fclose($handle);
        }

        return $valid && $previous !== '';
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    private function signEvent(array $event): array
    {
        unset($event['hash'], $event['signature'], $event['prev_hash']);
        $event['prev_hash'] = $this->getLastHash($this->basePath);
        $event['hash'] = hash('sha256', PhpSerializer::encode($event));
        $event['signature'] = hash_hmac(
            'sha512',
            $event['hash'] . ':' . $event['prev_hash'],
            $this->signingKey
        );
        return $event;
    }

    private function verifyEvent(array $event, string $expectedPrevious): bool
    {
        $hash = $event['hash'] ?? null;
        $signature = $event['signature'] ?? null;
        $previous = $event['prev_hash'] ?? null;
        if (!is_string($hash) || !is_string($signature) || $previous !== $expectedPrevious) {
            return false;
        }

        $unsigned = $event;
        unset($unsigned['hash'], $unsigned['signature']);
        $expectedHash = hash('sha256', PhpSerializer::encode($unsigned));
        $expectedSignature = hash_hmac('sha512', $hash . ':' . $previous, $this->signingKey);

        return hash_equals($expectedHash, $hash) && hash_equals($expectedSignature, $signature);
    }

    private function getLastHash(string $directory): string
    {
        $log = $directory . DIRECTORY_SEPARATOR . 'replication.log';
        if (!is_file($log)) {
            return '';
        }

        $handle = fopen($log, 'rb');
        if ($handle === false || fseek($handle, 0, SEEK_END) !== 0) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return '';
        }

        $position = ftell($handle) - 1;
        $line = '';
        while ($position >= 0) {
            fseek($handle, $position);
            $character = fgetc($handle);
            if ($character === "\n" && $line !== '') {
                break;
            }
            if ($character !== "\n" && $character !== "\r") {
                $line = $character . $line;
            }
            $position--;
        }
        fclose($handle);

        $last = PhpSerializer::decode($line);
        return is_array($last) && is_string($last['hash'] ?? null) ? $last['hash'] : '';
    }

    private function appendEvent(string $directory, array $event): bool
    {
        $payload = PhpSerializer::encode($event) . PHP_EOL;
        $written = file_put_contents(
            $directory . DIRECTORY_SEPARATOR . 'replication.log',
            $payload,
            FILE_APPEND | LOCK_EX
        );
        return $written === strlen($payload);
    }

    private function appendOrResyncReplica(string $directory, array $event): bool
    {
        $expectedPrevious = (string) ($event['prev_hash'] ?? '');
        $replicaLog = $directory . DIRECTORY_SEPARATOR . 'replication.log';
        if ((!is_file($replicaLog) && $expectedPrevious === '')
            || $this->getLastHash($directory) === $expectedPrevious) {
            return $this->appendEvent($directory, $event);
        }

        $primaryLog = $this->basePath . DIRECTORY_SEPARATOR . 'replication.log';
        $temporary = tempnam($directory, '.jah-replica-');
        if ($temporary === false) {
            return false;
        }
        try {
            if (!copy($primaryLog, $temporary)) {
                return false;
            }
            @chmod($temporary, 0660);
            return rename($temporary, $replicaLog);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if ($directory === '' || str_contains($directory, "\0")) {
            throw new RuntimeException('Invalid replication directory');
        }
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create replication directory: {$directory}");
        }
        if (!is_writable($directory)) {
            throw new RuntimeException("Replication directory is not writable: {$directory}");
        }
    }
}
