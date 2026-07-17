<?php

declare(strict_types=1);

namespace Jah\JAS\Observability;

use Jah\DataCore\PhpSerializer;
use RuntimeException;

final class JahLogger
{
    public function __construct(private readonly string $file, private readonly mixed $sanitizer = null)
    {
        if ($sanitizer !== null && !is_callable($sanitizer)) throw new RuntimeException('logger_sanitizer_invalid');
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('logger_directory_failed');
    }

    public function log(string $level, string $event, array $context = []): void
    {
        if (!in_array($level, ['debug', 'info', 'notice', 'warning', 'error', 'critical'], true)) throw new RuntimeException('logger_level_invalid');
        if (!preg_match('/^[a-z][a-z0-9_.:-]{2,127}$/', $event)) throw new RuntimeException('logger_event_invalid');
        if ($this->sanitizer !== null) $context = ($this->sanitizer)($context);
        $record = ['at' => microtime(true), 'level' => $level, 'event' => $event, 'context' => $context, 'pid' => getmypid()];
        $line = PhpSerializer::encode($record) . "\n";
        $handle = fopen($this->file, 'ab');
        if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('logger_write_failed');
        try {
            if (fwrite($handle, $line) !== strlen($line) || !fflush($handle)) throw new RuntimeException('logger_write_failed');
        } finally { flock($handle, LOCK_UN); fclose($handle); }
        @chmod($this->file, 0600);
    }

    public function records(): array
    {
        if (!is_file($this->file)) return [];
        $records = [];
        foreach (file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $record = PhpSerializer::decode($line);
            if (!is_array($record)) throw new RuntimeException('logger_record_corrupt');
            $records[] = $record;
        }
        return $records;
    }
}
