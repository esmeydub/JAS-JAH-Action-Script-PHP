<?php

declare(strict_types=1);

namespace Jah\JAS\Persistence;

use Jah\DataCore\PhpSerializer;
use RuntimeException;

final class WalJournal
{
    private string $file;
    private string $lock;
    public function __construct(string $directory, string $name = 'jas')
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $name)) throw new RuntimeException('Nombre WAL inválido');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("No se pudo crear WAL: {$directory}");
        }
        $this->file = rtrim($directory, '/') . '/' . $name . '.wal';
        $this->lock = rtrim($directory, '/') . '/' . $name . '.lock';
    }

    public function begin(string $txId, string $operation, array $payload): void
    {
        $this->validateTransaction($txId);
        if ($operation === '' || strlen($operation) > 255) throw new RuntimeException('wal_operation_invalid');
        $this->append(['type'=>'BEGIN','tx'=>$txId,'operation'=>$operation,'payload'=>$payload,'at'=>microtime(true)]);
    }
    public function commit(string $txId, array $result = []): void
    {
        $this->validateTransaction($txId);
        $this->append(['type'=>'COMMIT','tx'=>$txId,'result'=>$result,'at'=>microtime(true)]);
    }
    public function abort(string $txId, string $reason): void
    {
        $this->validateTransaction($txId);
        if ($reason === '') $reason = 'aborted';
        $this->append(['type'=>'ABORT','tx'=>$txId,'reason'=>$reason,'at'=>microtime(true)]);
    }

    /** @return array<string,array{operation:string,payload:array}> */
    public function pending(): array
    {
        return $this->locked(fn(): array => $this->pendingUnlocked());
    }

    /** @return array{compacted:bool,entries_before:int,entries_after:int,bytes_before:int,dry_run:bool} */
    public function compact(bool $dryRun = true): array
    {
        return $this->locked(function () use ($dryRun): array {
            $entries = $this->entriesUnlocked();
            $pending = [];
            foreach ($entries as $entry) {
                $tx = (string) ($entry['tx'] ?? '');
                if (($entry['type'] ?? '') === 'BEGIN') $pending[$tx] = $entry;
                elseif (($entry['type'] ?? '') === 'COMMIT' || ($entry['type'] ?? '') === 'ABORT') unset($pending[$tx]);
            }
            $bytes = is_file($this->file) ? (int) filesize($this->file) : 0;
            if (!$dryRun) $this->replaceUnlocked(array_values($pending));
            return [
                'compacted' => !$dryRun,
                'entries_before' => count($entries),
                'entries_after' => count($pending),
                'bytes_before' => $bytes,
                'dry_run' => $dryRun,
            ];
        });
    }

    /** @return array<string,array{operation:string,payload:array}> */
    private function pendingUnlocked(): array
    {
        $transactions = [];
        foreach ($this->entriesUnlocked() as $entry) {
            if (!isset($entry['tx'], $entry['type'])) continue;
            $tx = (string)$entry['tx'];
            if ($entry['type'] === 'BEGIN') $transactions[$tx] = ['operation'=>(string)($entry['operation'] ?? ''),'payload'=>(array)($entry['payload'] ?? [])];
            elseif ($entry['type'] === 'COMMIT' || $entry['type'] === 'ABORT') unset($transactions[$tx]);
        }
        return $transactions;
    }

    private function append(array $entry): void
    {
        $this->locked(function () use ($entry): void {
            $encoded = PhpSerializer::encode($entry) . "\n";
            $h = fopen($this->file, 'ab');
            if ($h === false) throw new RuntimeException('No se pudo abrir WAL');
            try {
            $written = fwrite($h, $encoded);
            if ($written !== strlen($encoded) || !fflush($h)) throw new RuntimeException('Escritura WAL incompleta');
            if (function_exists('fsync') && !@fsync($h)) throw new RuntimeException('Sincronización WAL incompleta');
            } finally { fclose($h); }
            @chmod($this->file, 0600);
        });
    }

    /** @return list<array> */
    private function entriesUnlocked(): array
    {
        if (!is_file($this->file)) return [];
        $entries = [];
        foreach (file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = PhpSerializer::decode($line);
            if (!is_array($entry)) throw new RuntimeException('wal_corrupt');
            $entries[] = $entry;
        }
        return $entries;
    }

    /** @param list<array> $entries */
    private function replaceUnlocked(array $entries): void
    {
        $temporary = $this->file . '.compact.' . bin2hex(random_bytes(4));
        $handle = fopen($temporary, 'xb');
        if ($handle === false) throw new RuntimeException('wal_compaction_prepare_failed');
        try {
            foreach ($entries as $entry) {
                $line = PhpSerializer::encode($entry) . "\n";
                if (fwrite($handle, $line) !== strlen($line)) throw new RuntimeException('wal_compaction_write_failed');
            }
            if (!fflush($handle) || (function_exists('fsync') && !@fsync($handle))) throw new RuntimeException('wal_compaction_sync_failed');
        } finally { fclose($handle); }
        if (!rename($temporary, $this->file)) { @unlink($temporary); throw new RuntimeException('wal_compaction_publish_failed'); }
        @chmod($this->file, 0600);
    }

    private function locked(callable $operation): mixed
    {
        $handle = fopen($this->lock, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('wal_lock_failed');
        try { return $operation(); } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function validateTransaction(string $txId): void
    {
        if ($txId === '' || strlen($txId) > 255 || preg_match('/[\x00-\x1F\x7F]/', $txId)) {
            throw new RuntimeException('wal_transaction_id_invalid');
        }
    }
}
