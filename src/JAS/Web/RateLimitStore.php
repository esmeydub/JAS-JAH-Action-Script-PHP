<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use Jah\DataCore\PhpSerializer;
use RuntimeException;

final class RateLimitStore
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('rate_limit_directory_failed');
    }

    /** @return array{allowed:bool,remaining:int,retry_after:int} */
    public function consume(string $key, int $limit, int $windowSeconds): array
    {
        if ($key === '' || strlen($key) > 255) throw new RuntimeException('rate_limit_key_invalid');
        if ($limit < 1 || $limit > 1_000_000 || $windowSeconds < 1 || $windowSeconds > 86_400) throw new RuntimeException('rate_limit_config_invalid');
        $path = rtrim($this->directory, '/') . '/' . hash('sha256', $key) . '.limit';
        $handle = fopen($path, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('rate_limit_lock_failed');
        try {
            $record = PhpSerializer::decode(stream_get_contents($handle));
            $now = time();
            if (!is_array($record) || (int) ($record['reset_at'] ?? 0) <= $now) {
                $record = ['count' => 0, 'reset_at' => $now + $windowSeconds];
            }
            $allowed = (int) $record['count'] < $limit;
            if ($allowed) $record['count'] = (int) $record['count'] + 1;
            $encoded = PhpSerializer::encode($record);
            ftruncate($handle, 0); rewind($handle);
            if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) throw new RuntimeException('rate_limit_write_failed');
            if (function_exists('fsync')) @fsync($handle);
            @chmod($path, 0600);
            return [
                'allowed' => $allowed,
                'remaining' => max(0, $limit - (int) $record['count']),
                'retry_after' => max(1, (int) $record['reset_at'] - $now),
            ];
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
