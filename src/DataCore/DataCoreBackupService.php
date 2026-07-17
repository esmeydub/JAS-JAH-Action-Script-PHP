<?php

declare(strict_types=1);

namespace Jah\DataCore;

use Jah\JAS\Security\KeyRing;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use RuntimeException;

final class DataCoreBackupService
{
    private const FORMAT = 'JAS-DATACORE-BACKUP-1';
    /** @var list<callable():void> */
    private array $flushers;
    /** @var callable():float */
    private mixed $clock;

    public function __construct(
        private readonly string $sourceDirectory,
        private readonly string $backupDirectory,
        private readonly KeyRing $keys,
        private readonly DataCoreContinuityLock $continuity,
        private readonly int $maximumFileBytes = 67_108_864,
        array $flushers = [],
        ?callable $clock = null,
    ) {
        if (!is_dir($sourceDirectory)) throw new RuntimeException('datacore_backup_source_missing');
        if ($maximumFileBytes < 1_024 || $maximumFileBytes > 1_073_741_824) {
            throw new RuntimeException('datacore_backup_file_limit_invalid');
        }
        if (!is_dir($backupDirectory)
            && !mkdir($backupDirectory, 0700, true)
            && !is_dir($backupDirectory)) {
            throw new RuntimeException('datacore_backup_directory_failed');
        }
        $source = realpath($sourceDirectory);
        $backup = realpath($backupDirectory);
        if ($source === false || $backup === false || str_starts_with($backup . '/', $source . '/')) {
            throw new RuntimeException('datacore_backup_directory_inside_source');
        }
        foreach ($flushers as $flusher) {
            if (!is_callable($flusher)) throw new RuntimeException('datacore_backup_flusher_invalid');
        }
        $this->flushers = array_values($flushers);
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    public function create(string $backupId): array
    {
        $this->backupId($backupId);
        $target = rtrim($this->backupDirectory, '/') . '/' . $backupId . '.jahb';
        if (is_file($target)) throw new RuntimeException('datacore_backup_exists');
        return $this->continuity->exclusive(function () use ($backupId, $target): array {
            foreach ($this->flushers as $flusher) $flusher();
            $createdAt = ($this->clock)();
            if (!is_float($createdAt) && !is_int($createdAt)) {
                throw new RuntimeException('datacore_backup_clock_invalid');
            }
            $createdAt = (float) $createdAt;
            $entries = [];
            foreach ($this->sourceFiles() as $relative => $path) {
                $bytes = filesize($path);
                if (!is_int($bytes) || $bytes > $this->maximumFileBytes) {
                    throw new RuntimeException('datacore_backup_source_file_too_large');
                }
                $plain = file_get_contents($path);
                if (!is_string($plain)) throw new RuntimeException('datacore_backup_read_failed');
                $encrypted = $this->keys->encrypt('datacore-backup-file:' . $relative, $plain);
                $entries[] = [
                    'path' => $relative,
                    'bytes' => $bytes,
                    'sha256' => hash('sha256', $plain),
                    'key_id' => $encrypted['key_id'],
                    'ciphertext' => $encrypted['ciphertext'],
                ];
            }
            $manifest = [
                'format' => self::FORMAT,
                'id' => $backupId,
                'created_at' => $createdAt,
                'point_in_time' => $createdAt,
                'files' => count($entries),
                'entries' => $entries,
            ];
            $payload = PhpSerializer::encode($manifest);
            $signed = $this->keys->sign('datacore-backup-archive', $payload);
            $archive = PhpSerializer::encode([
                'manifest' => $manifest,
                'signature_key_id' => $signed['key_id'],
                'signature' => $signed['signature'],
            ]);
            $temporary = $target . '.tmp.' . bin2hex(random_bytes(5));
            $handle = fopen($temporary, 'xb');
            if ($handle === false) throw new RuntimeException('datacore_backup_create_failed');
            try {
                if (fwrite($handle, $archive) !== strlen($archive) || !fflush($handle)
                    || (function_exists('fsync') && !fsync($handle))) {
                    throw new RuntimeException('datacore_backup_write_failed');
                }
            } finally {
                fclose($handle);
            }
            @chmod($temporary, 0600);
            if (!rename($temporary, $target)) {
                @unlink($temporary);
                throw new RuntimeException('datacore_backup_publish_failed');
            }
            return [
                'id' => $backupId,
                'path' => $target,
                'files' => count($entries),
                'bytes' => strlen($archive),
                'point_in_time' => $createdAt,
            ];
        });
    }

    public function restorePointInTime(float $timestamp, string $destination): array
    {
        if (!is_finite($timestamp) || $timestamp <= 0) {
            throw new RuntimeException('datacore_restore_point_invalid');
        }
        $candidate = null;
        $candidateTime = 0.0;
        foreach (glob(rtrim($this->backupDirectory, '/') . '/*.jahb') ?: [] as $path) {
            $id = substr(basename($path), 0, -5);
            try {
                $manifest = $this->decoded($id, true);
            } catch (\Throwable) {
                continue;
            }
            $point = (float) ($manifest['point_in_time'] ?? 0);
            if ($point <= $timestamp && $point >= $candidateTime) {
                $candidate = $id;
                $candidateTime = $point;
            }
        }
        if ($candidate === null) throw new RuntimeException('datacore_restore_point_not_found');
        return $this->restore($candidate, $destination) + ['restored_point' => $candidateTime];
    }

    public function prune(int $keepNewest, int $maximumAgeSeconds, bool $dryRun = true): array
    {
        if ($keepNewest < 1 || $keepNewest > 10_000 || $maximumAgeSeconds < 60) {
            throw new RuntimeException('datacore_backup_retention_policy_invalid');
        }
        $valid = [];
        foreach (glob(rtrim($this->backupDirectory, '/') . '/*.jahb') ?: [] as $path) {
            $id = substr(basename($path), 0, -5);
            try {
                $manifest = $this->decoded($id, true);
                $valid[] = ['id' => $id, 'created_at' => (float) ($manifest['created_at'] ?? 0)];
            } catch (\Throwable) {
                // Un backup corrupto requiere investigación; nunca se elimina automáticamente.
            }
        }
        usort($valid, static fn(array $left, array $right): int => $right['created_at'] <=> $left['created_at']);
        $now = ($this->clock)();
        if (!is_float($now) && !is_int($now)) throw new RuntimeException('datacore_backup_clock_invalid');
        $cutoff = (float) $now - $maximumAgeSeconds;
        $expired = [];
        foreach ($valid as $position => $backup) {
            if ($position < $keepNewest || $backup['created_at'] >= $cutoff) continue;
            $expired[] = $backup['id'];
            if (!$dryRun) {
                $path = rtrim($this->backupDirectory, '/') . '/' . $backup['id'] . '.jahb';
                if (!unlink($path)) throw new RuntimeException('datacore_backup_retention_delete_failed');
            }
        }
        return ['expired' => $expired, 'deleted' => $dryRun ? 0 : count($expired), 'dry_run' => $dryRun];
    }

    public function verify(string $backupId): bool
    {
        try {
            $this->decoded($backupId, true);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function restore(string $backupId, string $destination): array
    {
        if (is_dir($destination)) {
            $items = array_diff(scandir($destination) ?: [], ['.', '..']);
            if ($items !== []) throw new RuntimeException('datacore_restore_destination_not_empty');
        } elseif (!mkdir($destination, 0700, true) && !is_dir($destination)) {
            throw new RuntimeException('datacore_restore_directory_failed');
        }
        $manifest = $this->decoded($backupId, true);
        $staging = rtrim($destination, '/') . '/.restore-' . bin2hex(random_bytes(5));
        if (!mkdir($staging, 0700, true)) throw new RuntimeException('datacore_restore_staging_failed');
        try {
            foreach ($manifest['entries'] as $entry) {
                $relative = $this->safeRelativePath((string) $entry['path']);
                $plain = $this->keys->decrypt(
                    'datacore-backup-file:' . $relative,
                    (string) $entry['key_id'],
                    (string) $entry['ciphertext'],
                );
                if (strlen($plain) !== (int) $entry['bytes']
                    || !hash_equals((string) $entry['sha256'], hash('sha256', $plain))) {
                    throw new RuntimeException('datacore_backup_entry_integrity_failed');
                }
                $path = $staging . '/' . $relative;
                $parent = dirname($path);
                if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                    throw new RuntimeException('datacore_restore_directory_failed');
                }
                if (file_put_contents($path, $plain, LOCK_EX) !== strlen($plain)) {
                    throw new RuntimeException('datacore_restore_write_failed');
                }
                @chmod($path, 0600);
            }
            foreach (array_diff(scandir($staging) ?: [], ['.', '..']) as $name) {
                if (!rename($staging . '/' . $name, rtrim($destination, '/') . '/' . $name)) {
                    throw new RuntimeException('datacore_restore_publish_failed');
                }
            }
            @rmdir($staging);
        } catch (\Throwable $error) {
            $this->removeTree($staging);
            throw $error;
        }
        return ['id' => $backupId, 'files' => (int) $manifest['files'], 'destination' => $destination];
    }

    private function decoded(string $backupId, bool $verifyEntries): array
    {
        $this->backupId($backupId);
        $path = rtrim($this->backupDirectory, '/') . '/' . $backupId . '.jahb';
        $encoded = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($encoded)) throw new RuntimeException('datacore_backup_not_found');
        $archive = PhpSerializer::decode($encoded);
        $manifest = is_array($archive) ? ($archive['manifest'] ?? null) : null;
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('datacore_backup_manifest_invalid');
        }
        $keyId = $archive['signature_key_id'] ?? null;
        $signature = $archive['signature'] ?? null;
        if (!is_string($keyId) || !is_string($signature)
            || !$this->keys->verify(
                'datacore-backup-archive',
                PhpSerializer::encode($manifest),
                $keyId,
                $signature,
            )) {
            throw new RuntimeException('datacore_backup_signature_invalid');
        }
        $entries = $manifest['entries'] ?? null;
        if (!is_array($entries) || count($entries) !== (int) ($manifest['files'] ?? -1)) {
            throw new RuntimeException('datacore_backup_manifest_invalid');
        }
        if ($verifyEntries) {
            foreach ($entries as $entry) {
                if (!is_array($entry)) throw new RuntimeException('datacore_backup_entry_invalid');
                $relative = $this->safeRelativePath((string) ($entry['path'] ?? ''));
                $plain = $this->keys->decrypt(
                    'datacore-backup-file:' . $relative,
                    (string) ($entry['key_id'] ?? ''),
                    (string) ($entry['ciphertext'] ?? ''),
                );
                if (strlen($plain) !== (int) ($entry['bytes'] ?? -1)
                    || !hash_equals((string) ($entry['sha256'] ?? ''), hash('sha256', $plain))) {
                    throw new RuntimeException('datacore_backup_entry_integrity_failed');
                }
            }
        }
        return $manifest;
    }

    /** @return array<string,string> */
    private function sourceFiles(): array
    {
        $root = rtrim((string) realpath($this->sourceDirectory), '/');
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) throw new RuntimeException('datacore_backup_symlink_forbidden');
            if (!$entry->isFile()) continue;
            $path = $entry->getPathname();
            $relative = $this->safeRelativePath(substr($path, strlen($root) + 1));
            $files[$relative] = $path;
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    private function safeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new RuntimeException('datacore_backup_path_invalid');
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException('datacore_backup_path_invalid');
            }
        }
        return $path;
    }

    private function backupId(string $backupId): void
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{2,127}$/', $backupId)) {
            throw new RuntimeException('datacore_backup_id_invalid');
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) return;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        @rmdir($path);
    }
}
