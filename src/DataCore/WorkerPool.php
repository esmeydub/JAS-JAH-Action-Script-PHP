<?php

declare(strict_types=1);

namespace Jah\DataCore;

use RuntimeException;
use Throwable;

/**
 * Bounded PHP process pool for StorageAgent inserts.
 */
final class WorkerPool
{
    public function __construct(private string $basePath, private int $workers = 4)
    {
        $this->workers = max(1, $workers);
    }

    public function parallelInsert(string $collection, array $docs): int
    {
        if ($docs === []) {
            return 0;
        }
        if ($this->workers === 1 || !function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            return $this->insertChunk($collection, $docs);
        }

        $chunks = array_chunk($docs, (int) ceil(count($docs) / $this->workers));
        $processes = [];
        $inserted = 0;
        $errors = [];

        foreach ($chunks as $chunk) {
            $resultFile = tempnam(sys_get_temp_dir(), 'jah-pool-');
            if ($resultFile === false) {
                throw new RuntimeException('Could not create worker result channel');
            }

            $pid = pcntl_fork();
            if ($pid === -1) {
                @unlink($resultFile);
                $inserted += $this->insertChunk($collection, $chunk);
                continue;
            }
            if ($pid === 0) {
                $this->runChild($collection, $chunk, $resultFile);
            }
            $processes[$pid] = $resultFile;
        }

        foreach ($processes as $pid => $resultFile) {
            $waited = pcntl_waitpid($pid, $status);
            try {
                $payload = file_get_contents($resultFile);
                $result = is_string($payload)
                    ? PhpSerializer::decode($payload)
                    : null;
                if ($waited !== $pid || !is_array($result) || ($result['ok'] ?? false) !== true) {
                    $errors[] = is_array($result)
                        ? (string) ($result['error'] ?? 'worker failed')
                        : 'worker returned no valid result';
                    continue;
                }
                if (function_exists('pcntl_wifexited') && (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0)) {
                    $errors[] = 'worker exited abnormally';
                    continue;
                }
                $inserted += (int) ($result['inserted'] ?? 0);
            } finally {
                @unlink($resultFile);
            }
        }

        if ($errors !== []) {
            throw new RuntimeException('Parallel insert incomplete: ' . implode('; ', $errors));
        }
        if ($inserted !== count($docs)) {
            throw new RuntimeException("Parallel insert count mismatch: {$inserted}/" . count($docs));
        }

        return $inserted;
    }

    private function insertChunk(string $collection, array $docs): int
    {
        $storage = new StorageAgent($this->basePath . '/data');
        try {
            foreach ($docs as $doc) {
                if (!is_array($doc)) {
                    throw new RuntimeException('Every parallel insert document must be an array');
                }
                $storage->insert($collection, $doc);
            }
        } finally {
            $storage->close();
        }
        return count($docs);
    }

    private function runChild(string $collection, array $docs, string $resultFile): never
    {
        try {
            $result = ['ok' => true, 'inserted' => $this->insertChunk($collection, $docs)];
        } catch (Throwable $error) {
            $result = ['ok' => false, 'error' => $error->getMessage()];
        }

        $payload = PhpSerializer::encode($result);
        $written = file_put_contents($resultFile, $payload, LOCK_EX);
        exit($written === strlen($payload) && $result['ok'] === true ? 0 : 1);
    }
}
