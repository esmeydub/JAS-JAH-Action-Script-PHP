<?php

declare(strict_types=1);

namespace Jah\DataCore;

use Jah\JAS\Security\KeyRing;
use RuntimeException;

final class SqlMirrorAuditJournal
{
    private string $file;

    public function __construct(string $directory, private readonly KeyRing $keys)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('sql_mirror_audit_directory_failed');
        }
        $this->file = rtrim($directory, '/') . '/reconciliation-audit.jahl';
    }

    public function record(array $result): void
    {
        $entry = [
            'type' => 'RECONCILIATION',
            'document_id' => (string) ($result['document_id'] ?? ''),
            'status' => (string) ($result['status'] ?? ''),
            'datacore_version' => (int) ($result['datacore_version'] ?? 0),
            'sql_version' => isset($result['sql_version']) ? (int) $result['sql_version'] : null,
            'at' => microtime(true),
        ];
        if ($entry['document_id'] === '' || !in_array($entry['status'], [
            'in_sync', 'missing', 'sql_behind', 'sql_ahead_untrusted', 'diverged',
        ], true)) {
            throw new RuntimeException('sql_mirror_audit_result_invalid');
        }
        $signed = $this->keys->sign('datacore-sql-reconciliation', PhpSerializer::encode($entry));
        $entry['signature'] = $signed['signature'];
        $entry['signature_key_id'] = $signed['key_id'];
        $line = PhpSerializer::encode($entry) . "\n";
        if (file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new RuntimeException('sql_mirror_audit_write_failed');
        }
        @chmod($this->file, 0600);
    }

    public function verify(): bool
    {
        if (!is_file($this->file)) return true;
        foreach (file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = PhpSerializer::decode($line);
            if (!is_array($entry)) return false;
            $signature = $entry['signature'] ?? null;
            $keyId = $entry['signature_key_id'] ?? null;
            unset($entry['signature'], $entry['signature_key_id']);
            if (!is_string($signature) || !is_string($keyId)
                || !$this->keys->verify(
                    'datacore-sql-reconciliation',
                    PhpSerializer::encode($entry),
                    $keyId,
                    $signature,
                )) {
                return false;
            }
        }
        return true;
    }
}
