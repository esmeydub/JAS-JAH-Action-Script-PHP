<?php

declare(strict_types=1);

namespace Jah\DataCore;

/**
 * StorageAgent - Almacenamiento append-only con segmentos
 */
final class StorageAgent
{
    private string $basePath;
    private int $segmentSize = 10000;
    private array $indexes = [];

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
        if (!is_dir($this->basePath) && !mkdir($this->basePath, 0700, true) && !is_dir($this->basePath)) {
            throw new \RuntimeException("Cannot create storage directory: {$this->basePath}");
        }
    }

    public function insert(string $collection, array $doc): string
    {
        $collection = $this->sanitizeCollection($collection);
        $id = (string)($doc['id'] ?? bin2hex(random_bytes(16)));
        $this->assertValidId($id);
        $doc['id'] = $id;
        $doc['_ts'] ??= time();

        $segment = $this->getSegment($collection, $id);
        $payloadHash = hash('sha256', PhpSerializer::encode($doc));
        $record = PhpSerializer::encode([
            'id' => $id,
            'collection' => $collection,
            'payload' => $doc,
            'ts' => $doc['_ts'] ?? time(),
            'hash' => $payloadHash,
        ]) . "\n";

        $file = $this->basePath . "/{$collection}_{$segment}.jahl";
        $handle = fopen($file, 'c+b');
        if ($handle === false) throw new \RuntimeException("Cannot open storage file: {$file}");
        try {
            if (!flock($handle, LOCK_EX)) throw new \RuntimeException("Cannot lock storage file: {$file}");
            $lineOffset = $this->countLines($handle);
            fseek($handle, 0, SEEK_END);
            $this->writeAll($handle, $record, $file);
            if (!fflush($handle)) throw new \RuntimeException("Cannot flush storage file: {$file}");
            $this->indexRecord($collection, $id, $segment, $lineOffset);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $id;
    }

    public function find(string $collection, string $id): ?array
    {
        $collection = $this->sanitizeCollection($collection);
        $idx = $this->loadIndex($collection);
        if (!isset($idx[$id])) {
            return null;
        }

        [$segment, $line] = $idx[$id];
        $file = $this->basePath . "/{$collection}_{$segment}.jahl";

        if (!file_exists($file)) {
            return null;
        }

        $lines = file($file);
        $record = PhpSerializer::decode($lines[$line] ?? '{}', true);

        $payload = $record['payload'] ?? null;
        if (!is_array($payload) || ($payload['_deleted'] ?? false) === true) {
            return null;
        }

        return $payload;
    }

    public function query(string $collection, callable $filter): array
    {
        $collection = $this->sanitizeCollection($collection);
        $latest = [];
        foreach (glob($this->basePath . "/{$collection}_*.jahl") as $file) {
            foreach (file($file) as $line) {
                $record = PhpSerializer::decode($line, true);
                if (is_array($record) && isset($record['id'], $record['payload']) && is_array($record['payload'])) {
                    $latest[(string) $record['id']] = $record['payload'];
                }
            }
        }

        $results = [];
        foreach ($latest as $payload) {
            if (($payload['_deleted'] ?? false) !== true && $filter($payload)) {
                $results[] = $payload;
            }
        }
        return $results;
    }

    public function update(string $collection, string $id, array $patch): bool
    {
        $doc = $this->find($collection, $id);
        if ($doc === null) {
            return false;
        }

        $this->insert($collection, array_merge($doc, $patch));
        return true;
    }

    public function markDeleted(string $collection, string $id): bool
    {
        $doc = $this->find($collection, $id);
        if ($doc === null) {
            return false;
        }

        $doc['_deleted'] = true;
        $this->insert($collection, $doc);
        return true;
    }

    private function getSegment(string $collection, string $id): int
    {
        return (int) ((crc32($id) % 1000000) / $this->segmentSize);
    }

    private function indexRecord(string $collection, string $id, int $segment, int $line): void
    {
        $indexFile = $this->basePath . "/{$collection}.idx";
        file_put_contents($indexFile, rawurlencode($id) . ":{$segment}:{$line}\n", FILE_APPEND | LOCK_EX);
        $this->indexes[$collection][$id] = [$segment, $line];
    }

    private function loadIndex(string $collection): array
    {
        if (isset($this->indexes[$collection])) {
            return $this->indexes[$collection];
        }

        $idx = [];
        $file = $this->basePath . "/{$collection}.idx";
        if (file_exists($file)) {
            foreach (file($file) as $line) {
                $parts = explode(':', trim($line));
                if (count($parts) === 3) {
                    $idx[rawurldecode($parts[0])] = [(int) $parts[1], (int) $parts[2]];
                }
            }
        }
        return $this->indexes[$collection] = $idx;
    }

    public function getStats(): array
    {
        $stats = [];
        foreach (glob($this->basePath . "/*.jahl") as $file) {
            $basename = basename($file, '.jahl');
            $collection = explode('_', $basename)[0];
            $stats[$collection] = ($stats[$collection] ?? 0) + count(file($file));
        }
        return $stats;
    }

    public function close(): void
    {
        $this->indexes = [];
    }

    private function sanitizeCollection(string $collection): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', $collection) ?: 'memories';
        return trim($clean, '_') !== '' ? $clean : 'memories';
    }

    private function assertValidId(string $id): void
    {
        if ($id === '' || strlen($id) > 255 || preg_match('/[\x00-\x1F\x7F]/', $id) === 1) {
            throw new \InvalidArgumentException('Storage id must contain 1-255 printable bytes');
        }
    }

    private function countLines($handle): int
    {
        rewind($handle);
        $lines = 0;
        while (fgets($handle) !== false) $lines++;
        return $lines;
    }

    private function writeAll($handle, string $record, string $file): void
    {
        $offset = 0;
        $length = strlen($record);
        while ($offset < $length) {
            $written = fwrite($handle, substr($record, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException("Cannot write storage file: {$file}");
            }
            $offset += $written;
        }
    }
}
