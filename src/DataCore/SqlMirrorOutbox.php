<?php

declare(strict_types=1);

namespace Jah\DataCore;

use Jah\JAS\Security\KeyRing;
use RuntimeException;

final class SqlMirrorOutbox
{
    private string $file;
    private string $lock;
    /** @var null|callable(string):bool */
    private mixed $transactionCommitted = null;

    public function __construct(
        string $directory,
        private readonly KeyRing $keys,
        private readonly ?DataCoreContinuityLock $continuity = null,
    )
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('sql_mirror_outbox_directory_failed');
        }
        $this->file = rtrim($directory, '/') . '/sql-mirror.jahl';
        $this->lock = rtrim($directory, '/') . '/sql-mirror.lock';
    }

    public function enqueue(
        string $operationId,
        string $collection,
        string $operation,
        string $documentId,
        int $version,
        array $projection,
        ?string $transactionId = null,
    ): void {
        if (!preg_match('/^[A-Za-z0-9_.:-]{3,255}$/', $operationId)) {
            throw new RuntimeException('sql_mirror_operation_id_invalid');
        }
        if (!in_array($operation, ['upsert', 'delete'], true) || $version < 1) {
            throw new RuntimeException('sql_mirror_operation_invalid');
        }
        $record = [
            'type' => 'PREPARED',
            'operation_id' => $operationId,
            'collection' => $collection,
            'operation' => $operation,
            'document_id' => $documentId,
            'version' => $version,
            'projection' => $projection,
            'transaction_id' => $transactionId,
            'at' => microtime(true),
        ];
        $this->appendSigned($record);
    }

    public function transactionVisibility(callable $committed): self
    {
        $this->transactionCommitted = $committed;
        return $this;
    }

    public function applied(string $operationId): void
    {
        $this->appendSigned([
            'type' => 'APPLIED',
            'operation_id' => $operationId,
            'at' => microtime(true),
        ]);
    }

    public function pending(): array
    {
        $pending = [];
        foreach ($this->entries() as $entry) {
            $this->assertSignature($entry);
            $id = (string) ($entry['operation_id'] ?? '');
            if (($entry['type'] ?? null) === 'PREPARED') {
                $transactionId = $entry['transaction_id'] ?? null;
                if (is_string($transactionId) && $transactionId !== '') {
                    if ($this->transactionCommitted === null
                        || ($this->transactionCommitted)($transactionId) !== true) {
                        continue;
                    }
                }
                $pending[$id] = $entry;
            } elseif (($entry['type'] ?? null) === 'APPLIED') {
                unset($pending[$id]);
            }
        }
        return $pending;
    }

    private function assertSignature(array $entry): void
    {
        $signature = $entry['signature'] ?? null;
        $keyId = $entry['signature_key_id'] ?? null;
        unset($entry['signature'], $entry['signature_key_id']);
        if (!is_string($signature) || !is_string($keyId)
            || !$this->keys->verify(
                'datacore-sql-mirror',
                PhpSerializer::encode($entry),
                $keyId,
                $signature,
            )) {
            throw new RuntimeException('sql_mirror_outbox_signature_invalid');
        }
    }

    private function entries(): array
    {
        if (!is_file($this->file)) return [];
        $entries = [];
        foreach (file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = PhpSerializer::decode($line);
            if (!is_array($entry)) throw new RuntimeException('sql_mirror_outbox_corrupt');
            $entries[] = $entry;
        }
        return $entries;
    }

    private function append(array $entry): void
    {
        if ($this->continuity !== null) {
            $this->continuity->shared(fn() => $this->appendUnlocked($entry));
            return;
        }
        $this->appendUnlocked($entry);
    }

    private function appendUnlocked(array $entry): void
    {
        $handle = fopen($this->lock, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('sql_mirror_outbox_lock_failed');
        }
        try {
            $line = PhpSerializer::encode($entry) . "\n";
            if (file_put_contents($this->file, $line, FILE_APPEND) !== strlen($line)) {
                throw new RuntimeException('sql_mirror_outbox_write_failed');
            }
            @chmod($this->file, 0600);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function appendSigned(array $entry): void
    {
        $signed = $this->keys->sign('datacore-sql-mirror', PhpSerializer::encode($entry));
        $entry['signature'] = $signed['signature'];
        $entry['signature_key_id'] = $signed['key_id'];
        $this->append($entry);
    }
}
