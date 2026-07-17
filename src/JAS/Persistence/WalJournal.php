<?php

declare(strict_types=1);

namespace Jah\JAS\Persistence;

use Jah\DataCore\PhpSerializer;
use RuntimeException;

final class WalJournal
{
    private string $file;
    public function __construct(string $directory, string $name = 'jas')
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $name)) throw new RuntimeException('Nombre WAL inválido');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("No se pudo crear WAL: {$directory}");
        }
        $this->file = rtrim($directory, '/') . '/' . $name . '.wal';
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
        $transactions = [];
        if (!is_file($this->file)) return [];
        $h = fopen($this->file, 'rb');
        if ($h === false) return [];
        flock($h, LOCK_SH);
        try {
            while (($line = fgets($h)) !== false) {
                $entry = PhpSerializer::decode(trim($line));
                if (!is_array($entry) || !isset($entry['tx'], $entry['type'])) continue;
                $tx = (string)$entry['tx'];
                if ($entry['type'] === 'BEGIN') {
                    $transactions[$tx] = ['operation'=>(string)($entry['operation'] ?? ''),'payload'=>(array)($entry['payload'] ?? [])];
                } elseif ($entry['type'] === 'COMMIT' || $entry['type'] === 'ABORT') {
                    unset($transactions[$tx]);
                }
            }
        } finally { flock($h, LOCK_UN); fclose($h); }
        return $transactions;
    }

    private function append(array $entry): void
    {
        $encoded = PhpSerializer::encode($entry) . "\n";
        $h = fopen($this->file, 'ab');
        if ($h === false) throw new RuntimeException('No se pudo abrir WAL');
        try {
            if (!flock($h, LOCK_EX)) throw new RuntimeException('No se pudo bloquear WAL');
            $written = fwrite($h, $encoded);
            if ($written !== strlen($encoded) || !fflush($h)) throw new RuntimeException('Escritura WAL incompleta');
            if (function_exists('fsync') && !@fsync($h)) throw new RuntimeException('Sincronización WAL incompleta');
        } finally { flock($h, LOCK_UN); fclose($h); }
    }

    private function validateTransaction(string $txId): void
    {
        if ($txId === '' || strlen($txId) > 255 || preg_match('/[\x00-\x1F\x7F]/', $txId)) {
            throw new RuntimeException('wal_transaction_id_invalid');
        }
    }
}
