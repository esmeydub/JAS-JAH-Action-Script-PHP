<?php

declare(strict_types=1);

namespace Jah\JAS\Persistence;

use RuntimeException;

final class EventReceiptStore
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('event_receipt_directory_failed');
    }

    public function processOnce(string $consumer, string $eventId, callable $operation): bool
    {
        if (!preg_match('/^[a-z][a-z0-9_.:-]{2,127}$/', $consumer)) throw new RuntimeException('event_consumer_invalid');
        if (!preg_match('/^[A-Fa-f0-9]{32}$/', $eventId)) throw new RuntimeException('event_id_invalid');
        $path = rtrim($this->directory, '/') . '/' . hash('sha256', $consumer . "\0" . $eventId) . '.receipt';
        $lock = fopen($path . '.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('event_receipt_lock_failed');
        try {
            if (is_file($path)) return false;
            $operation();
            $temporary = $path . '.tmp.' . bin2hex(random_bytes(4));
            $content = $consumer . "\n" . $eventId . "\n" . sprintf('%.6F', microtime(true));
            $handle = fopen($temporary, 'xb');
            if ($handle === false) throw new RuntimeException('event_receipt_write_failed');
            try {
                if (fwrite($handle, $content) !== strlen($content) || !fflush($handle)) throw new RuntimeException('event_receipt_write_failed');
                if (function_exists('fsync')) @fsync($handle);
            } finally { fclose($handle); }
            if (!rename($temporary, $path)) { @unlink($temporary); throw new RuntimeException('event_receipt_publish_failed'); }
            @chmod($path, 0600);
            return true;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
