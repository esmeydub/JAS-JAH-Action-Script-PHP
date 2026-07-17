<?php

declare(strict_types=1);

namespace Jah\JAS\Persistence;

use Jah\DataCore\PhpSerializer;
use RuntimeException;

final class OutboxJournal
{
    private string $file;
    private string $lock;
    public function __construct(string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('outbox_directory_failed');
        $this->file = rtrim($directory, '/') . '/outbox.jahl';
        $this->lock = rtrim($directory, '/') . '/outbox.lock';
    }

    public function prepare(string $requestId, string $action, array $record): void
    {
        $this->append(['type' => 'PREPARED', 'request_id' => $requestId, 'action' => $action, 'record' => $record, 'at' => microtime(true)]);
    }
    public function applied(string $requestId): void { $this->append(['type' => 'APPLIED', 'request_id' => $requestId, 'at' => microtime(true)]); }

    public function pending(): array
    {
        $pending = [];
        foreach ($this->entries() as $entry) {
            $id = (string) ($entry['request_id'] ?? '');
            if (($entry['type'] ?? '') === 'PREPARED') $pending[$id] = $entry;
            elseif (($entry['type'] ?? '') === 'APPLIED') unset($pending[$id]);
        }
        return $pending;
    }

    private function entries(): array
    {
        if (!is_file($this->file)) return [];
        $entries = [];
        foreach (file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = PhpSerializer::decode($line);
            if (!is_array($entry)) throw new RuntimeException('outbox_corrupt');
            $entries[] = $entry;
        }
        return $entries;
    }

    private function append(array $entry): void
    {
        $handle = fopen($this->lock, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('outbox_lock_failed');
        try {
            $line = PhpSerializer::encode($entry) . "\n";
            $journal = fopen($this->file, 'ab');
            if ($journal === false) throw new RuntimeException('outbox_open_failed');
            try {
                if (fwrite($journal, $line) !== strlen($line) || !fflush($journal)) throw new RuntimeException('outbox_write_failed');
                if (function_exists('fsync')) @fsync($journal);
            } finally { fclose($journal); }
            @chmod($this->file, 0600);
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
