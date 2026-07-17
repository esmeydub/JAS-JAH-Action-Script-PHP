<?php

declare(strict_types=1);

namespace Jah\JAS\Consensus;

use Jah\DataCore\PhpSerializer;
use RuntimeException;

final class QuorumPrepareStore
{
    private string $file;

    public function __construct(string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('prepare_directory_failed');
        }
        $this->file = rtrim($directory, '/') . '/prepared.journal';
    }

    public function prepare(array $message): array
    {
        $operationId = (string) ($message['operation_id'] ?? '');
        $term = (int) ($message['term'] ?? 0);
        $fencingToken = (int) ($message['fencing_token'] ?? 0);
        if ($operationId === '' || $term < 1 || $fencingToken < 1) {
            return ['accepted' => false, 'error' => 'prepare_invalid'];
        }

        $latest = $this->latest($operationId);
        $isStale = $latest !== null && (
            (int) $latest['term'] > $term
            || ((int) $latest['term'] === $term && (int) $latest['fencing_token'] > $fencingToken)
        );
        if ($isStale) {
            return ['accepted' => false, 'error' => 'stale_prepare'];
        }

        $row = [
            'operation_id' => $operationId,
            'term' => $term,
            'fencing_token' => $fencingToken,
            'payload_hash' => hash('sha256', PhpSerializer::encode($message['payload'] ?? [])),
            'at' => microtime(true),
        ];
        $line = PhpSerializer::encode($row) . "\n";
        if (file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new RuntimeException('prepare_write_failed');
        }
        @chmod($this->file, 0600);

        return ['accepted' => true, 'operation_id' => $operationId];
    }

    public function latest(string $operationId): ?array
    {
        if (!is_file($this->file)) {
            return null;
        }

        $found = null;
        foreach (file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = PhpSerializer::decode($line);
            if (is_array($row) && ($row['operation_id'] ?? null) === $operationId) {
                $found = $row;
            }
        }
        return $found;
    }
}
