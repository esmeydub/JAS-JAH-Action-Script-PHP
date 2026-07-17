<?php

declare(strict_types=1);

namespace Jah\DataCore;

/**
 * MemoryPyramid
 * Ciclo de memoria Hot / Warm / Cold para JAS en PHP puro.
 * - Hot: in-request LRU cache
 * - Warm: persistent JAH serialized lines with file+offset index
 * - Cold: compressed historical files
 */
final class MemoryPyramid
{
    private string $basePath;
    private CacheAgent $hotCache;
    private WarmMemory $warmCache;
    private ColdMemory $coldStorage;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0700, true);
        }
        $this->hotCache = new CacheAgent(10000);
        $this->warmCache = new WarmMemory($this->basePath . '/warm');
        $this->coldStorage = new ColdMemory($this->basePath . '/cold');
    }

    public function get(string $key): mixed
    {
        $val = $this->hotCache->get($key);
        if ($val !== null) {
            return $val;
        }

        $val = $this->warmCache->get($key);
        if ($val !== null) {
            $this->hotCache->set($key, $val);
            return $val;
        }

        $val = $this->coldStorage->get($key);
        if ($val !== null) {
            $this->hotCache->set($key, $val);
        }

        return $val;
    }

    public function set(string $key, mixed $value, int $ttl = 0, string $tier = 'hot'): void
    {
        $this->hotCache->set($key, $value);
        if ($tier === 'warm') {
            $this->warmCache->set($key, $value);
        } elseif ($tier === 'cold') {
            $this->coldStorage->set($key, $value);
        }
    }

    public function flushCold(): void
    {
        $this->coldStorage->flush();
    }

    public function stats(): array
    {
        return [
            'hot_entries' => count($this->hotCache->getAll()),
            'warm_files' => count(glob($this->warmCache->getPath() . '/*.jahl') ?: []),
            'cold_files' => count(glob($this->coldStorage->getPath() . '/*.jahp.gz') ?: []),
        ];
    }
}

class WarmMemory
{
    private string $path;
    private array $index = [];

    public function __construct(string $path)
    {
        $this->path = rtrim($path, '/');
        if (!is_dir($this->path)) {
            mkdir($this->path, 0700, true);
        }
    }

    public function get(string $key): mixed
    {
        $idx = $this->buildIndex();
        if (!isset($idx[$key])) {
            return null;
        }

        [$file, $offset] = $idx[$key];
        if (!is_file($file)) {
            return null;
        }

        $handle = fopen($file, 'r');
        if ($handle === false) {
            return null;
        }
        fseek($handle, (int)$offset);
        $line = fgets($handle);
        fclose($handle);

        $record = $line !== false ? PhpSerializer::decode($line, true) : null;
        return is_array($record) ? ($record['payload'] ?? null) : null;
    }

    public function set(string $key, mixed $value): void
    {
        $file = $this->path . '/warm_' . date('Ymd') . '.jahl';
        $record = PhpSerializer::encode(['key' => $key, 'payload' => $value]);
        $handle = fopen($file, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open warm memory file: {$file}");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Cannot lock warm memory file: {$file}");
            }
            fseek($handle, 0, SEEK_END);
            $offset = ftell($handle);
            if ($offset === false) {
                throw new \RuntimeException("Cannot write warm memory file: {$file}");
            }
            $data = $record . "\n";
            $written = 0;
            while ($written < strlen($data)) {
                $bytes = fwrite($handle, substr($data, $written));
                if ($bytes === false || $bytes === 0) throw new \RuntimeException("Cannot write warm memory file: {$file}");
                $written += $bytes;
            }
            if (!fflush($handle)) throw new \RuntimeException("Cannot flush warm memory file: {$file}");
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $this->index[$key] = [$file, $offset];
        $encodedFile = str_replace(':', '%3A', $file);
        $encodedKey = rawurlencode($key);
        if (file_put_contents($this->path . '/.index', "{$encodedKey}:{$encodedFile}:{$offset}\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Cannot update warm memory index');
        }
    }

    private function buildIndex(): array
    {
        if ($this->index !== []) {
            return $this->index;
        }

        $indexFile = $this->path . '/.index';
        if (!is_file($indexFile)) {
            return $this->index;
        }

        foreach (file($indexFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $parts = explode(':', $line);
            if (count($parts) >= 3) {
                $key = rawurldecode((string)array_shift($parts));
                $offset = (int)array_pop($parts);
                $file = str_replace('%3A', ':', implode(':', $parts));
                $this->index[$key] = [$file, $offset];
            } elseif (count($parts) === 2) {
                // Backward compatibility with old index format.
                $this->index[$parts[0]] = [$this->path . '/warm_' . date('Ymd') . '.jahl', (int)$parts[1]];
            }
        }

        return $this->index;
    }

    public function getPath(): string
    {
        return $this->path;
    }
}

class ColdMemory
{
    private string $path;
    private array $queue = [];

    public function __construct(string $path)
    {
        $this->path = rtrim($path, '/');
        if (!is_dir($this->path)) {
            mkdir($this->path, 0700, true);
        }
    }

    public function get(string $key): mixed
    {
        $files = glob($this->path . '/*.jahp.gz') ?: [];
        rsort($files, SORT_STRING);
        foreach ($files as $file) {
            $tmp = tempnam(sys_get_temp_dir(), 'jah_cold_');
            if ($tmp === false) {
                continue;
            }

            $ok = Compressor::decompressFile($file, $tmp, 'gzip');
            $raw = $ok ? file_get_contents($tmp) : false;
            @unlink($tmp);

            $data = $raw !== false ? PhpSerializer::decode($raw, true) : null;
            if (is_array($data) && array_key_exists($key, $data)) {
                return $data[$key];
            }
        }
        return null;
    }

    public function schedule(string $key, mixed $value, int $expire): void
    {
        $this->queue[] = ['key' => $key, 'value' => $value, 'expire' => $expire];

        if (count($this->queue) > 5000) {
            $this->flush();
        }
    }

    public function set(string $key, mixed $value): void
    {
        $payload = PhpSerializer::encode([$key => $value]);
        $file = $this->path . '/cold_' . hash('sha256', $key) . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.jahp.gz';
        if (file_put_contents($file, Compressor::compress($payload, 'gzip'), LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write cold memory file: {$file}");
        }
    }

    public function flush(): void
    {
        $now = time();
        $expired = array_filter($this->queue, static fn(array $item): bool => (int)$item['expire'] <= $now);

        if ($expired === []) {
            return;
        }

        $data = [];
        foreach ($expired as $item) {
            $data[(string)$item['key']] = $item['value'];
        }

        $payload = PhpSerializer::encode($data);
        if ($payload !== '') {
            $file = $this->path . '/cold_' . time() . '_' . bin2hex(random_bytes(3)) . '.jahp.gz';
            file_put_contents($file, Compressor::compress($payload, 'gzip'), LOCK_EX);
        }

        $this->queue = array_values(array_filter(
            $this->queue,
            static fn(array $item): bool => (int)$item['expire'] > $now
        ));
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
