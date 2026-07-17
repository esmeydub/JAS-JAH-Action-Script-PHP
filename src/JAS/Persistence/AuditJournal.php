<?php

declare(strict_types=1);

namespace Jah\JAS\Persistence;

use Jah\DataCore\PhpSerializer;
use RuntimeException;

final class AuditJournal
{
    private string $file;
    private string $lock;

    public function __construct(string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('audit_directory_failed');
        $this->file = rtrim($directory, '/') . '/audit.jahl';
        $this->lock = rtrim($directory, '/') . '/audit.lock';
    }

    public function record(string $principal, string $action, string $requestId, bool $success, string $inputFingerprint, ?string $errorCode = null): void
    {
        $handle = fopen($this->lock, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('audit_lock_failed');
        try {
            $previous = str_repeat('0', 64);
            if (is_file($this->file)) {
                $lines = file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    $existing = PhpSerializer::decode($line);
                    if (is_array($existing) && ($existing['request_id'] ?? null) === $requestId && ($existing['action'] ?? null) === $action) return;
                }
                if ($lines !== []) {
                    $last = PhpSerializer::decode((string) end($lines));
                    if (!is_array($last) || !is_string($last['hash'] ?? null)) throw new RuntimeException('audit_corrupt');
                    $previous = $last['hash'];
                }
            }
            $entry = [
                'at' => microtime(true), 'principal' => $principal, 'action' => $action,
                'request_id' => $requestId, 'success' => $success,
                'input_fingerprint' => $inputFingerprint, 'error_code' => $errorCode,
                'previous_hash' => $previous,
            ];
            $entry['hash'] = hash('sha256', PhpSerializer::encode($entry));
            $line = PhpSerializer::encode($entry) . "\n";
            $audit = fopen($this->file, 'ab');
            if ($audit === false) throw new RuntimeException('audit_open_failed');
            try {
                if (fwrite($audit, $line) !== strlen($line) || !fflush($audit)) throw new RuntimeException('audit_write_failed');
                if (function_exists('fsync')) @fsync($audit);
            } finally { fclose($audit); }
            @chmod($this->file, 0600);
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    public function verify(): bool
    {
        $previous = str_repeat('0', 64);
        if (!is_file($this->file)) return true;
        foreach (file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = PhpSerializer::decode($line);
            if (!is_array($entry) || ($entry['previous_hash'] ?? null) !== $previous) return false;
            $hash = $entry['hash'] ?? null; unset($entry['hash']);
            if (!is_string($hash) || !hash_equals($hash, hash('sha256', PhpSerializer::encode($entry)))) return false;
            $previous = $hash;
        }
        return true;
    }
}
