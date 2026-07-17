<?php

declare(strict_types=1);

namespace Jah\DataCore;

use RuntimeException;

final class DataCoreTransactionManager
{
    private string $journal;
    private string $lock;
    private int $lockDepth = 0;
    /** @var null|callable(string,string):void */
    private mixed $failureProbe = null;
    public function __construct(
        private readonly DataCoreDatabase $database,
        string $directory,
        private readonly ?DataCoreContinuityLock $continuity = null,
    )
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('datacore_transaction_directory_failed');
        }
        $directory = rtrim($directory, '/');
        $this->journal = $directory . '/transactions.jahl';
        $this->lock = $directory . '/transactions.lock';
        $this->database->transactionVisibility(fn(string $id): bool => $this->isCommitted($id));
        $this->database->compactionGuard(fn(): bool => $this->pendingCount() === 0);
    }
    public function begin(?string $id = null): DataCoreTransaction
    {
        $id ??= bin2hex(random_bytes(16));
        if (!preg_match('/^[A-Za-z0-9_.:-]{3,128}$/', $id)) {
            throw new RuntimeException('datacore_transaction_id_invalid');
        }
        return new DataCoreTransaction($id);
    }

    public function failureProbe(?callable $probe): self
    {
        $this->failureProbe = $probe;
        return $this;
    }
    public function commit(DataCoreTransaction $transaction): void
    {
        $transaction->close();
        if ($this->continuity !== null) {
            $this->continuity->shared(fn() => $this->commitUnlocked($transaction));
            return;
        }
        $this->commitUnlocked($transaction);
    }

    private function commitUnlocked(DataCoreTransaction $transaction): void
    {
        $this->locked(function () use ($transaction): void {
            $this->append(['type' => 'PREPARED', 'id' => $transaction->id, 'operations' => $transaction->operations(), 'at' => microtime(true)]);
            $this->probe('prepared', $transaction->id);
            $this->apply($transaction->id, $transaction->operations());
            $this->probe('before_committed', $transaction->id);
            $this->append(['type' => 'COMMITTED', 'id' => $transaction->id, 'at' => microtime(true)]);
            $this->probe('committed', $transaction->id);
        });
    }
    public function recover(): int
    {
        if ($this->continuity !== null) {
            return $this->continuity->shared(fn(): int => $this->recoverUnlocked());
        }
        return $this->recoverUnlocked();
    }

    private function recoverUnlocked(): int
    {
        return $this->locked(function (): int {
            $pending = [];
            foreach ($this->entries() as $entry) {
                $id = (string) ($entry['id'] ?? '');
                if (($entry['type'] ?? '') === 'PREPARED') {
                    $pending[$id] = (array) ($entry['operations'] ?? []);
                } elseif (($entry['type'] ?? '') === 'COMMITTED') {
                    unset($pending[$id]);
                }
            }
            foreach ($pending as $id => $operations) {
                $this->apply($id, $operations);
                $this->append([
                    'type' => 'COMMITTED',
                    'id' => $id,
                    'recovered' => true,
                    'at' => microtime(true),
                ]);
            }
            return count($pending);
        });
    }

    public function isCommitted(string $transactionId): bool
    {
        return $this->locked(function () use ($transactionId): bool {
            $committed = false;
            foreach ($this->entries() as $entry) {
                if (($entry['id'] ?? null) !== $transactionId) continue;
                if (($entry['type'] ?? null) === 'PREPARED') $committed = false;
                if (($entry['type'] ?? null) === 'COMMITTED') $committed = true;
            }
            return $committed;
        });
    }

    public function pendingCount(): int
    {
        return $this->locked(function (): int {
            $pending = [];
            foreach ($this->entries() as $entry) {
                $id = (string) ($entry['id'] ?? '');
                if ($id === '') continue;
                if (($entry['type'] ?? null) === 'PREPARED') $pending[$id] = true;
                if (($entry['type'] ?? null) === 'COMMITTED') unset($pending[$id]);
            }
            return count($pending);
        });
    }

    public function pendingTransactions(): array
    {
        return $this->locked(function (): array {
            $pending = [];
            foreach ($this->entries() as $entry) {
                $id = (string) ($entry['id'] ?? '');
                if ($id === '') continue;
                if (($entry['type'] ?? null) === 'PREPARED') {
                    $pending[$id] = (array) ($entry['operations'] ?? []);
                }
                if (($entry['type'] ?? null) === 'COMMITTED') unset($pending[$id]);
            }

            $report = [];
            foreach ($pending as $id => $operations) {
                $applied = 0;
                foreach ($operations as $operation) {
                    $collection = (string) ($operation['collection'] ?? '');
                    $documentId = (string) ($operation['id'] ?? ($operation['document']['id'] ?? ''));
                    if ($collection === '' || $documentId === '') continue;
                    $document = $this->database->findForTransaction($collection, $documentId, $id);
                    if (($document['_transaction_id'] ?? null) === $id) $applied++;
                }
                $state = match (true) {
                    $applied === 0 => 'prepared',
                    $applied === count($operations) => 'fully_applied_uncommitted',
                    default => 'partially_applied',
                };
                $report[$id] = [
                    'state' => $state,
                    'applied_operations' => $applied,
                    'total_operations' => count($operations),
                ];
            }
            return $report;
        });
    }
    private function apply(string $transactionId, array $operations): void
    {
        foreach ($operations as $index => $operation) {
            $type = $operation['type'] ?? '';
            if ($type === 'insert') {
                $existing = $this->database->findForTransaction(
                    (string) $operation['collection'],
                    (string) ($operation['document']['id'] ?? ''),
                    $transactionId,
                );
                if (($existing['_transaction_id'] ?? null) === $transactionId) {
                    continue;
                }
                $this->database->insert((string) $operation['collection'], (array) $operation['document'], $transactionId);
            } elseif ($type === 'update') {
                $this->database->update(
                    (string) $operation['collection'],
                    (string) $operation['id'],
                    (array) $operation['document'],
                    (int) $operation['expected_version'],
                    $transactionId,
                );
            } elseif ($type === 'delete') {
                $collection = (string) $operation['collection'];
                $id = (string) $operation['id'];
                $applied = $this->database->findForTransaction($collection, $id, $transactionId);
                if (($applied['_transaction_id'] ?? null) === $transactionId) {
                    continue;
                }
                $existing = $this->database->find($collection, $id);
                if ($existing !== null) {
                    $this->database->delete(
                        $collection,
                        $id,
                        (int) $operation['expected_version'],
                        $transactionId,
                    );
                }
            } else {
                throw new RuntimeException('datacore_transaction_operation_invalid');
            }
            $this->probe('operation:' . $index, $transactionId);
        }
    }

    private function probe(string $point, string $transactionId): void
    {
        if ($this->failureProbe !== null) {
            ($this->failureProbe)($point, $transactionId);
        }
    }
    private function entries(): array
    {
        if (!is_file($this->journal)) {
            return [];
        }
        $entries = [];
        foreach (file($this->journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = PhpSerializer::decode($line);
            if (!is_array($entry)) {
                throw new RuntimeException('datacore_transaction_journal_corrupt');
            }
            $entries[] = $entry;
        }
        return $entries;
    }
    private function append(array $entry): void
    {
        $line = PhpSerializer::encode($entry) . "\n";
        $handle = fopen($this->journal, 'ab');
        if ($handle === false) {
            throw new RuntimeException('datacore_transaction_write_failed');
        }
        try {
            if (fwrite($handle, $line) !== strlen($line) || !fflush($handle)) {
                throw new RuntimeException('datacore_transaction_write_failed');
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('datacore_transaction_sync_failed');
            }
        } finally {
            fclose($handle);
        }
        @chmod($this->journal, 0600);
    }
    private function locked(callable $operation): mixed
    {
        if ($this->lockDepth > 0) {
            $this->lockDepth++;
            try {
                return $operation();
            } finally {
                $this->lockDepth--;
            }
        }

        $handle = fopen($this->lock, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('datacore_transaction_lock_failed');
        }
        $this->lockDepth = 1;
        try {
            return $operation();
        } finally {
            $this->lockDepth = 0;
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
