<?php

declare(strict_types=1);

namespace Jah\JAS\Persistence;

use RuntimeException;

final class EventCursorStore
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('event_cursor_directory_failed');
    }

    public function get(string $consumer): int
    {
        $path = $this->path($consumer);
        if (!is_file($path)) return 0;
        $value = trim((string) file_get_contents($path));
        if (!ctype_digit($value)) throw new RuntimeException('event_cursor_corrupt');
        return (int) $value;
    }

    public function advance(string $consumer, int $sequence): void
    {
        if ($sequence < 1) throw new RuntimeException('event_cursor_sequence_invalid');
        $path = $this->path($consumer);
        $lock = fopen($path . '.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('event_cursor_lock_failed');
        try {
            $current = $this->get($consumer);
            if ($sequence <= $current) return;
            if ($sequence !== $current + 1) throw new RuntimeException('event_cursor_gap');
            $temporary = $path . '.tmp.' . bin2hex(random_bytes(4));
            $encoded = (string) $sequence;
            $handle = fopen($temporary, 'xb');
            if ($handle === false) throw new RuntimeException('event_cursor_write_failed');
            try {
                if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) throw new RuntimeException('event_cursor_write_failed');
                if (function_exists('fsync')) @fsync($handle);
            } finally { fclose($handle); }
            if (!rename($temporary, $path)) { @unlink($temporary); throw new RuntimeException('event_cursor_publish_failed'); }
            @chmod($path, 0600);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function path(string $consumer): string
    {
        if (!preg_match('/^[a-z][a-z0-9_.:-]{2,127}$/', $consumer)) throw new RuntimeException('event_consumer_invalid');
        return rtrim($this->directory, '/') . '/' . hash('sha256', $consumer) . '.cursor';
    }
}
