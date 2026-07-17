<?php

declare(strict_types=1);

namespace Jah\JAS\Observability;

use Jah\DataCore\PhpSerializer;
use Jah\DataCore\WriteAdmission;
use RuntimeException;

final class JahLogger
{
    private readonly string $lockFile;

    public function __construct(
        private readonly string $file,
        private readonly mixed $sanitizer = null,
        private readonly ?WriteAdmission $writeAdmission = null,
    )
    {
        if ($sanitizer !== null && !is_callable($sanitizer)) throw new RuntimeException('logger_sanitizer_invalid');
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('logger_directory_failed');
        $this->lockFile = $file . '.lock';
    }

    public function log(string $level, string $event, array $context = []): void
    {
        if (!in_array($level, ['debug', 'info', 'notice', 'warning', 'error', 'critical'], true)) throw new RuntimeException('logger_level_invalid');
        if (!preg_match('/^[a-z][a-z0-9_.:-]{2,127}$/', $event)) throw new RuntimeException('logger_event_invalid');
        if ($this->sanitizer !== null) $context = ($this->sanitizer)($context);
        $record = ['at' => microtime(true), 'level' => $level, 'event' => $event, 'context' => $context, 'pid' => getmypid()];
        $line = PhpSerializer::encode($record) . "\n";
        $this->writeAdmission?->assertWritable(
            'observability.log.append', strlen($line), in_array($level, ['warning', 'error', 'critical'], true)
        );
        $this->locked(function () use ($line): void {
            $handle = fopen($this->file, 'ab');
            if ($handle === false) throw new RuntimeException('logger_write_failed');
            try {
                if (fwrite($handle, $line) !== strlen($line) || !fflush($handle)) throw new RuntimeException('logger_write_failed');
                if (function_exists('fsync')) @fsync($handle);
            } finally { fclose($handle); }
            @chmod($this->file, 0600);
        });
    }

    public function records(): array
    {
        return $this->locked(fn(): array => $this->readRecords($this->file));
    }

    /** @return array{rotated:bool,deleted:list<string>,retained:int,bytes_before:int,dry_run:bool} */
    public function rotate(int $maxBytes, int $keepArchives, int $maximumAgeSeconds, bool $dryRun = true, ?int $now = null): array
    {
        if ($maxBytes < 1_024 || $keepArchives < 1 || $keepArchives > 100 || $maximumAgeSeconds < 60) {
            throw new RuntimeException('logger_retention_policy_invalid');
        }
        $now ??= time();
        return $this->locked(function () use ($maxBytes, $keepArchives, $maximumAgeSeconds, $dryRun, $now): array {
            if (is_link($this->file)) throw new RuntimeException('logger_symlink_forbidden');
            $bytes = is_file($this->file) ? (int) filesize($this->file) : 0;
            if (is_file($this->file)) $this->readRecords($this->file);
            $archives = glob($this->file . '.archive.*.jahl') ?: [];
            foreach ($archives as $archive) {
                if (is_link($archive) || !is_file($archive)) throw new RuntimeException('logger_archive_invalid');
                $this->readRecords($archive);
            }
            usort($archives, static fn(string $left, string $right): int => ((int) filemtime($right)) <=> ((int) filemtime($left)));
            $shouldRotate = $bytes >= $maxBytes;
            $existingLimit = $shouldRotate ? $keepArchives - 1 : $keepArchives;
            $delete = [];
            foreach ($archives as $index => $archive) {
                if ($index >= $existingLimit || (int) filemtime($archive) < $now - $maximumAgeSeconds) $delete[] = $archive;
            }
            if (!$dryRun) {
                if ($shouldRotate && is_file($this->file)) {
                    $archive = $this->file . '.archive.' . gmdate('YmdHis', $now) . '.' . bin2hex(random_bytes(4)) . '.jahl';
                    if (!rename($this->file, $archive)) throw new RuntimeException('logger_rotation_failed');
                    @chmod($archive, 0600);
                    $archives[] = $archive;
                }
                foreach ($delete as $archive) if (!unlink($archive)) throw new RuntimeException('logger_retention_delete_failed');
            }
            return [
                'rotated' => $shouldRotate,
                'deleted' => array_map('basename', $delete),
                'retained' => count($archives) - count($delete) + ($dryRun && $shouldRotate ? 1 : 0),
                'bytes_before' => $bytes,
                'dry_run' => $dryRun,
            ];
        });
    }

    private function readRecords(string $file): array
    {
        if (!is_file($file)) return [];
        $records = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $record = PhpSerializer::decode($line);
            if (!is_array($record)) throw new RuntimeException('logger_record_corrupt');
            $records[] = $record;
        }
        return $records;
    }

    private function locked(callable $operation): mixed
    {
        $lock = fopen($this->lockFile, 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('logger_lock_failed');
        try { return $operation(); } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
