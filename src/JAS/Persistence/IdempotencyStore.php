<?php

declare(strict_types=1);

namespace Jah\JAS\Persistence;

use Jah\DataCore\PhpSerializer;
use RuntimeException;

final class IdempotencyStore
{
    public function __construct(private readonly string $directory, private readonly int $ttlSeconds = 86_400)
    {
        if ($ttlSeconds < 60) throw new RuntimeException('idempotency_ttl_invalid');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('idempotency_directory_failed');
        }
    }

    public function get(string $action, string $requestId, string $inputFingerprint): ?array
    {
        $path = $this->path($action, $requestId);
        if (!is_file($path)) return null;
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('idempotency_read_failed');
        try {
            if (!flock($handle, LOCK_SH)) throw new RuntimeException('idempotency_lock_failed');
            $record = PhpSerializer::decode(stream_get_contents($handle));
        } finally { flock($handle, LOCK_UN); fclose($handle); }
        if (!is_array($record) || !isset($record['expires_at'], $record['input_fingerprint'], $record['result'])) throw new RuntimeException('idempotency_record_corrupt');
        if ((int) $record['expires_at'] < time()) { @unlink($path); return null; }
        if (!hash_equals((string) $record['input_fingerprint'], $inputFingerprint)) throw new RuntimeException('idempotency_input_mismatch');
        return is_array($record['result']) ? $record['result'] : null;
    }

    public function put(string $action, string $requestId, string $inputFingerprint, array $result): void
    {
        $path = $this->path($action, $requestId);
        $lock = fopen($path . '.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('idempotency_lock_failed');
        try {
            if (is_file($path)) return;
            $record = PhpSerializer::encode(['expires_at' => time() + $this->ttlSeconds, 'input_fingerprint' => $inputFingerprint, 'result' => $result]);
            $temporary = $path . '.tmp.' . bin2hex(random_bytes(4));
            $handle = fopen($temporary, 'xb');
            if ($handle === false) throw new RuntimeException('idempotency_write_failed');
            try {
                if (fwrite($handle, $record) !== strlen($record) || !fflush($handle)) throw new RuntimeException('idempotency_write_failed');
                if (function_exists('fsync')) @fsync($handle);
            } finally { fclose($handle); }
            if (!rename($temporary, $path)) { @unlink($temporary); throw new RuntimeException('idempotency_publish_failed'); }
            @chmod($path, 0600);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function executeOnce(
        string $action,
        string $requestId,
        string $inputFingerprint,
        callable $operation,
        ?callable $afterPersist = null,
    ): array
    {
        $path = $this->path($action, $requestId);
        $lock = fopen($path . '.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('idempotency_lock_failed');
        try {
            $stored = $this->readPath($path, $inputFingerprint);
            if ($stored !== null) return $stored + ['replayed' => true];
            $result = $operation();
            if (!is_array($result)) throw new RuntimeException('idempotency_result_invalid');
            $this->writePath($path, $inputFingerprint, $result);
            if ($afterPersist !== null) $afterPersist($result);
            return $result;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function path(string $action, string $requestId): string
    {
        if ($requestId === '' || strlen($requestId) > 255) throw new RuntimeException('idempotency_request_id_invalid');
        return rtrim($this->directory, '/') . '/' . hash('sha256', $action . "\0" . $requestId) . '.result';
    }

    private function readPath(string $path, string $inputFingerprint): ?array
    {
        if (!is_file($path)) return null;
        $record = PhpSerializer::decode(file_get_contents($path));
        if (!is_array($record) || !isset($record['expires_at'], $record['input_fingerprint'], $record['result'])) {
            throw new RuntimeException('idempotency_record_corrupt');
        }
        if ((int) $record['expires_at'] < time()) { @unlink($path); return null; }
        if (!hash_equals((string) $record['input_fingerprint'], $inputFingerprint)) throw new RuntimeException('idempotency_input_mismatch');
        return is_array($record['result']) ? $record['result'] : null;
    }

    private function writePath(string $path, string $inputFingerprint, array $result): void
    {
        $record = PhpSerializer::encode(['expires_at' => time() + $this->ttlSeconds, 'input_fingerprint' => $inputFingerprint, 'result' => $result]);
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(4));
        $handle = fopen($temporary, 'xb');
        if ($handle === false) throw new RuntimeException('idempotency_write_failed');
        try {
            if (fwrite($handle, $record) !== strlen($record) || !fflush($handle)) throw new RuntimeException('idempotency_write_failed');
            if (function_exists('fsync')) @fsync($handle);
        } finally { fclose($handle); }
        if (!rename($temporary, $path)) { @unlink($temporary); throw new RuntimeException('idempotency_publish_failed'); }
        @chmod($path, 0600);
    }
}
