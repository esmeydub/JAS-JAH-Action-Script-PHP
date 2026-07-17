<?php

declare(strict_types=1);

namespace Jah\DataCore;

/**
 * DataCoreTurbo
 * Almacenamiento binario append-only para JAS en PHP puro.
 *
 * Record format:
 * [4 bytes little-endian length][JAH PHP serialized payload][newline]
 *
 * Index format:
 * id:segment:offset:timestamp
 */
final class DataCoreTurbo
{
    private const MAX_RECORD_BYTES = 16_777_216;
    private const INDEX_VERSION = 3;
    private const MAX_INDEX_KEYS = 512;
    private const MAX_POSTING_CANDIDATES = 2_000;

    private string $basePath;
    private array $buffer = [];
    private int $batchSize;
    private array $indexesReady = [];
    private array $pointerCache = [];
    private array $pointerShardCache = [];
    private array $documentCache = [];
    /** @var null|callable():bool */
    private mixed $compactionAllowed = null;
    private ?DataCoreContinuityLock $continuity = null;
    private ?WriteAdmission $writeAdmission = null;

    public function __construct(string $basePath, int $batchSize = 1000, ?WriteAdmission $writeAdmission = null)
    {
        $this->basePath = rtrim($basePath, '/');
        $this->batchSize = max(1, $batchSize);
        $this->writeAdmission = $writeAdmission;
        $this->initDirs();
    }

    private function initDirs(): void
    {
        foreach (['data', 'index', 'index/lookup', 'index/terms', 'wal'] as $dir) {
            $path = "{$this->basePath}/{$dir}";
            if (!is_dir($path)) {
                mkdir($path, 0700, true);
            }
        }
    }

    public function insert(string $collection, array $doc): string
    {
        $collection = $this->sanitizeCollection($collection);
        $id = (string)($doc['id'] ?? bin2hex(random_bytes(8)));
        $this->assertValidId($id);
        $doc['id'] = $id;
        $doc['_ts'] = $doc['_ts'] ?? time();

        $this->buffer[] = ['collection' => $collection, 'doc' => $doc, 'id' => $id];

        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }

        return $id;
    }

    public function compactionGuard(callable $allowed): self
    {
        $this->compactionAllowed = $allowed;
        return $this;
    }

    public function continuityLock(DataCoreContinuityLock $lock): self
    {
        $this->continuity = $lock;
        return $this;
    }

    public function flush(): void
    {
        if ($this->continuity !== null) {
            $this->continuity->shared(fn() => $this->flushUnlocked());
            return;
        }
        $this->flushUnlocked();
    }

    private function flushUnlocked(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $estimatedBytes = 0;
        foreach ($this->buffer as $entry) $estimatedBytes += strlen(PhpSerializer::encode($entry['doc'])) + 256;
        $this->writeAdmission?->assertWritable('datacore.flush', min($estimatedBytes, 1_073_741_824));
        $batch = $this->buffer;
        $this->buffer = [];

        foreach ($batch as $entry) {
            $this->writeBinary($entry['collection'], $entry['doc'], $entry['id']);
        }
    }

    private function writeBinary(string $collection, array $doc, string $id): void
    {
        $this->ensureCollectionIndexes($collection);
        $collectionLock = fopen("{$this->basePath}/wal/{$collection}.compact.lock", 'c+b');
        if ($collectionLock === false || !flock($collectionLock, LOCK_SH)) throw new \RuntimeException('Cannot lock DataCore collection for write');
        try {
        $segment = $this->segmentForId($id);
        $file = "{$this->basePath}/data/{$collection}_{$segment}.bin";
        $indexFile = "{$this->basePath}/index/{$collection}.idx";

        $payload = PhpSerializer::encode(['id' => $id, 'payload' => $doc]);
        if ($payload === '') {
            return;
        }

        $record = pack('V', strlen($payload)) . $payload . "\n";

        $handle = fopen($file, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open DataCore segment: {$file}");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Cannot lock DataCore segment: {$file}");
            }
            fseek($handle, 0, SEEK_END);
            $offset = ftell($handle);
            if ($offset === false) {
                throw new \RuntimeException("Cannot determine DataCore offset: {$file}");
            }
            $this->writeAll($handle, $record, $file);
            if (!fflush($handle)) {
                throw new \RuntimeException("Cannot flush DataCore segment: {$file}");
            }

            $encodedId = rawurlencode($id);
            $indexLine = "{$encodedId}:{$segment}:{$offset}:" . time() . "\n";
            if (file_put_contents($indexFile, $indexLine, FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException("Cannot update DataCore index: {$indexFile}");
            }
            $this->writePointer($collection, $id, $segment, (int)$offset);
            if (($doc['_deleted'] ?? false) !== true) {
                $this->appendSearchPostings($collection, $id, $doc);
            }
            unset($this->documentCache[$collection . ':' . $id]);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        } finally {
            flock($collectionLock, LOCK_UN);
            fclose($collectionLock);
        }
    }

    public function find(string $collection, string $id): ?array
    {
        $this->flush();
        $collection = $this->sanitizeCollection($collection);
        $this->assertValidId($id);
        $this->ensureCollectionIndexes($collection);
        $cacheKey = $collection . ':' . $id;
        if (array_key_exists($cacheKey, $this->documentCache)) {
            return $this->documentCache[$cacheKey];
        }

        $pointer = $this->readPointer($collection, $id);
        if (is_array($pointer)) {
            $payload = $this->readBinary(
                $collection,
                (int)$pointer['segment'],
                $id,
                (int)$pointer['offset']
            );
            if (is_array($payload)) {
                $result = ($payload['_deleted'] ?? false) !== true ? $payload : null;
                $this->documentCache[$cacheKey] = $result;
                return $result;
            }
        }

        $indexFile = "{$this->basePath}/index/{$collection}.idx";
        if (!is_file($indexFile)) {
            $this->documentCache[$cacheKey] = null;
            return null;
        }

        $foundSegment = null;
        $foundOffset = null;

        $handle = fopen($indexFile, 'r');
        if ($handle === false) {
            return null;
        }

        flock($handle, LOCK_SH);
        try {
            while (($line = fgets($handle)) !== false) {
                $parts = explode(':', trim($line));
                $indexedId = rawurldecode((string)($parts[0] ?? ''));
                if ($indexedId === $id) {
                    $foundSegment = (int)($parts[1] ?? 0);
                    $foundOffset = isset($parts[2]) ? (int)$parts[2] : null;
                }
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        if ($foundSegment === null) {
            $this->documentCache[$cacheKey] = null;
            return null;
        }

        $this->writePointer($collection, $id, $foundSegment, (int)$foundOffset);
        $payload = $this->readBinary($collection, $foundSegment, $id, $foundOffset);
        if (($payload['_deleted'] ?? false) === true) {
            $this->documentCache[$cacheKey] = null;
            return null;
        }

        $this->documentCache[$cacheKey] = $payload;
        return $payload;
    }

    private function readBinary(string $collection, int $segment, string $targetId, ?int $offset = null): ?array
    {
        $file = "{$this->basePath}/data/{$collection}_{$segment}.bin";
        if (!is_file($file)) {
            return null;
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return null;
        }

        flock($handle, LOCK_SH);
        try {
            if ($offset !== null && $offset >= 0) {
                fseek($handle, $offset);
                return $this->readOneRecord($handle, $targetId);
            }

            $latest = null;
            while (!feof($handle)) {
                $payload = $this->readOneRecord($handle, $targetId);
                if ($payload !== null) {
                    $latest = $payload;
                }
            }
            return $latest;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function readOneRecord($handle, ?string $targetId = null): ?array
    {
        $lenData = fread($handle, 4);
        if ($lenData === false || strlen($lenData) < 4) {
            return null;
        }

        $unpacked = unpack('V', $lenData);
        $len = (int)($unpacked[1] ?? 0);
        if ($len <= 0 || $len > self::MAX_RECORD_BYTES) {
            return null;
        }

        $payload = fread($handle, $len);
        fgetc($handle);

        if ($payload === false || strlen($payload) !== $len) {
            return null;
        }

        $data = PhpSerializer::decode($payload);
        if (!is_array($data) || !isset($data['payload'])) {
            return null;
        }

        if ($targetId !== null && (string)($data['id'] ?? '') !== $targetId) {
            return null;
        }

        return is_array($data['payload']) ? $data['payload'] : null;
    }

    public function batchInsert(string $collection, array $docs): int
    {
        $collection = $this->sanitizeCollection($collection);
        $count = 0;

        foreach ($docs as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $normalized = $this->normalizeDoc($doc);
            $this->writeBinary($collection, $normalized, (string)$normalized['id']);
            $count++;
        }

        return $count;
    }

    public function query(string $collection, callable $filter): array
    {
        $results = [];
        foreach ($this->allLatest($collection) as $payload) {
            if ($filter($payload)) {
                $results[] = $payload;
            }
        }

        return $results;
    }

    /**
     * Returns recent candidates through the persistent inverted index.
     * Final semantic filtering remains in TieredMemory.
     */
    public function searchIndexed(string $collection, string $query, int $limit = 100): array
    {
        $this->flush();
        $collection = $this->sanitizeCollection($collection);
        $this->ensureCollectionIndexes($collection);
        $queryKeys = $this->queryIndexKeys($query);
        if ($queryKeys === []) return [];
        $postingSizes = [];
        foreach (array_keys($queryKeys) as $key) {
            $file = $this->postingFile($collection, $key);
            if (!is_file($file)) {
                unset($queryKeys[$key]);
                continue;
            }
            $postingSizes[$key] = (int)filesize($file);
        }
        if ($queryKeys === []) return [];
        uksort($queryKeys, static fn(string $a, string $b): int => $postingSizes[$a] <=> $postingSizes[$b]);
        $queryKeys = array_slice($queryKeys, 0, 48, true);

        $candidateLimit = max(100, min(self::MAX_POSTING_CANDIDATES, $limit * 50));
        $candidates = [];
        foreach ($queryKeys as $indexKey => $weight) {
            $postingFile = $this->postingFile($collection, $indexKey);
            $seenForKey = [];
            foreach ($this->postingLines($postingFile, $indexKey, $candidateLimit) as $line) {
                [, $timestamp, $encodedId] = array_pad(explode(':', $line, 3), 3, '');
                $id = rawurldecode($encodedId);
                if ($id === '' || isset($seenForKey[$id])) continue;
                $seenForKey[$id] = true;
                $candidates[$id] ??= ['score' => 0, 'ts' => 0];
                $candidates[$id]['score'] += $weight;
                $candidates[$id]['ts'] = max($candidates[$id]['ts'], (int)$timestamp);
            }
        }

        uasort($candidates, static function (array $a, array $b): int {
            $score = $b['score'] <=> $a['score'];
            return $score !== 0 ? $score : ($b['ts'] <=> $a['ts']);
        });

        $documents = [];
        foreach (array_slice($candidates, 0, $candidateLimit, true) as $id => $rank) {
            $doc = $this->find($collection, (string)$id);
            if (!is_array($doc)) continue;
            $doc['_search_score'] = (int)$rank['score'];
            $documents[] = $doc;
            if (count($documents) >= $limit) break;
        }

        return $documents;
    }

    public function rebuildIndexes(string $collection): array
    {
        $this->flush();
        $collection = $this->sanitizeCollection($collection);
        unset($this->indexesReady[$collection]);
        $marker = $this->indexMarker($collection);
        if (is_file($marker)) @unlink($marker);
        return $this->ensureCollectionIndexes($collection, true);
    }

    public function getIndexStats(string $collection): array
    {
        $collection = $this->sanitizeCollection($collection);
        $state = $this->ensureCollectionIndexes($collection);
        return [
            'version' => (int)($state['version'] ?? self::INDEX_VERSION),
            'collection' => $collection,
            'documents_at_rebuild' => (int)($state['documents'] ?? 0),
            'pointers_at_rebuild' => (int)($state['pointers'] ?? 0),
            'pointer_shards' => count(glob("{$this->basePath}/index/lookup/{$collection}/*.ptrlog") ?: []),
            'posting_files' => count(glob("{$this->basePath}/index/terms/{$collection}/*/*.post") ?: []),
            'built_at' => $state['built_at'] ?? null,
        ];
    }

    /** @return array<string,array> */
    public function allLatest(string $collection, bool $includeDeleted = false): array
    {
        $this->flush();
        $collection = $this->sanitizeCollection($collection);
        $latestById = [];

        foreach (glob("{$this->basePath}/data/{$collection}_*.bin") ?: [] as $file) {
            $handle = fopen($file, 'rb');
            if ($handle === false) continue;
            flock($handle, LOCK_SH);
            try {
                while (!feof($handle)) {
                    $payload = $this->readOneRecord($handle);
                    if ($payload === null) continue;
                    $id = (string)($payload['id'] ?? '');
                    if ($id !== '') $latestById[$id] = $payload;
                }
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }

        if (!$includeDeleted) {
            $latestById = array_filter(
                $latestById,
                static fn(array $payload): bool => ($payload['_deleted'] ?? false) !== true
            );
        }
        return $latestById;
    }

    public function findLatestMatching(string $collection, string $id, callable $accept): ?array
    {
        $this->flush();
        $collection = $this->sanitizeCollection($collection);
        $this->assertValidId($id);
        $file = "{$this->basePath}/data/{$collection}_{$this->segmentForId($id)}.bin";
        if (!is_file($file)) return null;

        $handle = fopen($file, 'rb');
        if ($handle === false || !flock($handle, LOCK_SH)) return null;
        $latest = null;
        try {
            while (!feof($handle)) {
                $document = $this->readOneRecord($handle, $id);
                if (is_array($document) && $accept($document) === true) {
                    $latest = $document;
                }
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        return $latest;
    }

    public function findIncludingDeleted(string $collection, string $id): ?array
    {
        return $this->findLatestMatching(
            $collection,
            $id,
            static fn(array $document): bool => true,
        );
    }

    public function delete(string $collection, string $id): void
    {
        $this->insert($collection, ['id' => $id, '_deleted' => true, '_ts' => time()]);
        $this->flush();
    }

    public function compactCollection(string $collection, bool $dryRun = true, bool $retainBackup = true, string $legalHoldField = '_legal_hold'): array
    {
        $this->recoverCompactions($collection);
        if ($this->compactionAllowed !== null && ($this->compactionAllowed)() !== true) {
            throw new \RuntimeException('datacore_compaction_transactions_pending');
        }
        $this->flush(); $collection = $this->sanitizeCollection($collection);
        $lock = fopen("{$this->basePath}/wal/{$collection}.compact.lock", 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new \RuntimeException('Cannot lock DataCore collection for compaction');
        try {
            $sourceFiles = glob("{$this->basePath}/data/{$collection}_*.bin") ?: [];
            $recordsBefore = $this->countRecordsInFiles($sourceFiles);
            $latest = $this->allLatest($collection, true);
            $heldIds = [];
            foreach ($latest as $id => $document) if (($document[$legalHoldField] ?? false) === true) $heldIds[$id] = true;
            $keep = [];
            foreach ($sourceFiles as $file) {
                $handle = fopen($file, 'rb'); if ($handle === false) throw new \RuntimeException('Cannot read DataCore compaction source');
                try {
                    while (!feof($handle)) {
                        $doc = $this->readOneRecord($handle);
                        if (!is_array($doc)) continue;
                        $id = (string) ($doc['id'] ?? '');
                        if (isset($heldIds[$id])) $keep[] = $doc;
                    }
                }
                finally { fclose($handle); }
            }
            foreach ($latest as $id => $document) {
                if (isset($heldIds[$id]) || ($document['_deleted'] ?? false) === true) continue;
                $keep[] = $document;
            }
            $report = [
                'collection' => $collection,
                'records_before' => $recordsBefore,
                'records_after' => count($keep),
                'records_removed' => max(0, $recordsBefore - count($keep)),
                'legal_hold_documents' => count($heldIds),
                'dry_run' => $dryRun,
            ];
            if ($dryRun || $sourceFiles === []) return $report;

            $operationId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
            $staging = "{$this->basePath}/wal/compact-{$collection}-{$operationId}";
            $backup = "{$this->basePath}/wal/backup-{$collection}-{$operationId}";
            if (!mkdir($staging, 0700, true) || !mkdir($backup, 0700, true)) throw new \RuntimeException('Cannot create DataCore compaction workspace');
            foreach ($keep as $document) $this->writeCompactedRecord($staging, $collection, $document);
            $stagedFiles = glob($staging . '/*.bin') ?: [];
            if ($this->countRecordsInFiles($stagedFiles) !== count($keep)) throw new \RuntimeException('DataCore compaction verification failed');
            $expectedHashes = array_map(static fn(array $doc): string => hash('sha256', PhpSerializer::encode($doc)), $keep);
            $stagedHashes = array_map(static fn(array $doc): string => hash('sha256', PhpSerializer::encode($doc)), $this->documentsInFiles($stagedFiles));
            sort($expectedHashes); sort($stagedHashes);
            if ($expectedHashes !== $stagedHashes) throw new \RuntimeException('DataCore compaction content verification failed');
            $manifest = [
                'state' => 'PREPARED',
                'collection' => $collection,
                'source' => array_map('basename', $sourceFiles),
                'staged' => array_map('basename', $stagedFiles),
                'report' => $report,
            ];
            file_put_contents($staging . '/manifest.jahl', PhpSerializer::encode($manifest), LOCK_EX);

            foreach ($sourceFiles as $file) if (!rename($file, $backup . '/' . basename($file))) throw new \RuntimeException('Cannot backup DataCore segment');
            try {
                foreach ($stagedFiles as $file) {
                    if (!rename($file, "{$this->basePath}/data/" . basename($file))) {
                        throw new \RuntimeException('Cannot publish compacted DataCore segment');
                    }
                }
            } catch (\Throwable $error) {
                foreach (glob($backup . '/*.bin') ?: [] as $file) @rename($file, "{$this->basePath}/data/" . basename($file));
                throw $error;
            }
            $manifest['state'] = 'PUBLISHED';
            file_put_contents($backup . '/manifest.jahl', PhpSerializer::encode($manifest), LOCK_EX);
            @unlink($staging . '/manifest.jahl'); @rmdir($staging);
            unset($this->indexesReady[$collection]); $this->pointerCache = []; $this->pointerShardCache = []; $this->documentCache = [];
            $this->rebuildIndexes($collection);
            if (!$retainBackup) $this->removeDirectory($backup);
            return $report + ['backup' => $retainBackup ? $backup : null];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function recoverCompactions(?string $collection = null): int
    {
        $recovered = 0;
        $pattern = rtrim($this->basePath, '/') . '/wal/compact-*';
        foreach (glob($pattern, GLOB_ONLYDIR) ?: [] as $staging) {
            $manifestPath = $staging . '/manifest.jahl';
            if (!is_file($manifestPath)) continue;
            $manifest = PhpSerializer::decode(trim((string) file_get_contents($manifestPath)));
            if (!is_array($manifest) || ($manifest['state'] ?? null) !== 'PREPARED') continue;
            $manifestCollection = (string) ($manifest['collection'] ?? '');
            if ($collection !== null && $manifestCollection !== $collection) continue;

            $suffix = substr(basename($staging), strlen('compact-'));
            $backup = rtrim($this->basePath, '/') . '/wal/backup-' . $suffix;
            foreach ((array) ($manifest['staged'] ?? []) as $name) {
                $published = rtrim($this->basePath, '/') . '/data/' . basename((string) $name);
                if (is_file($published)) @unlink($published);
            }
            foreach (glob($backup . '/*.bin') ?: [] as $source) {
                $destination = rtrim($this->basePath, '/') . '/data/' . basename($source);
                if (!rename($source, $destination)) {
                    throw new \RuntimeException('datacore_compaction_recovery_failed');
                }
            }
            $this->removeDirectory($staging);
            if (is_dir($backup)) $this->removeDirectory($backup);
            unset($this->indexesReady[$manifestCollection]);
            $this->pointerCache = [];
            $this->pointerShardCache = [];
            $this->documentCache = [];
            $recovered++;
        }
        return $recovered;
    }

    public function getStats(): array
    {
        $this->flush();
        $records = 0;
        $collections = [];

        foreach (glob("{$this->basePath}/data/*.bin") ?: [] as $file) {
            $name = basename($file);
            $collection = preg_replace('/_\d+\.bin$/', '', $name) ?: 'unknown';
            $collections[$collection] = true;

            $handle = fopen($file, 'rb');
            if ($handle === false) {
                continue;
            }
            while (!feof($handle)) {
                $lenData = fread($handle, 4);
                if ($lenData === false || strlen($lenData) < 4) {
                    break;
                }
                $len = (int)(unpack('V', $lenData)[1] ?? 0);
                fseek($handle, $len + 1, SEEK_CUR);
                $records++;
            }
            fclose($handle);
        }

        $documents = 0;
        foreach (array_keys($collections) as $collection) {
            $documents += count($this->allLatest($collection));
        }

        return [
            'records' => $records,
            'documents' => $documents,
            'collections' => count($collections),
            'buffered' => count($this->buffer),
        ];
    }

    public function close(): void
    {
        $this->flush();
    }

    private function normalizeDoc(array $doc): array
    {
        $id = (string)($doc['id'] ?? bin2hex(random_bytes(8)));
        $this->assertValidId($id);
        $doc['id'] = $id;
        $doc['_ts'] = $doc['_ts'] ?? time();
        return $doc;
    }

    private function writeCompactedRecord(string $directory, string $collection, array $document): void
    {
        $id = (string) ($document['id'] ?? ''); $this->assertValidId($id);
        $payload = PhpSerializer::encode(['id' => $id, 'payload' => $document]);
        $record = pack('V', strlen($payload)) . $payload . "\n";
        $file = $directory . '/' . $collection . '_' . $this->segmentForId($id) . '.bin';
        $handle = fopen($file, 'ab'); if ($handle === false) throw new \RuntimeException('Cannot create compacted DataCore segment');
        try {
            $this->writeAll($handle, $record, $file);
            if (!fflush($handle)) {
                throw new \RuntimeException('Cannot flush compacted DataCore segment');
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new \RuntimeException('Cannot sync compacted DataCore segment');
            }
        }
        finally { fclose($handle); }
    }

    private function countRecordsInFiles(array $files): int
    {
        $count = 0;
        foreach ($files as $file) {
            $handle = fopen($file, 'rb');
            if ($handle === false) continue;
            while (!feof($handle)) {
                $header = fread($handle, 4);
                if (!is_string($header) || strlen($header) < 4) break;
                $length = (int) (unpack('V', $header)[1] ?? 0);
                if ($length < 1 || $length > self::MAX_RECORD_BYTES) break;
                if (fseek($handle, $length + 1, SEEK_CUR) !== 0) break;
                $count++;
            }
            fclose($handle);
        }
        return $count;
    }

    private function documentsInFiles(array $files): array
    {
        $documents = [];
        foreach ($files as $file) {
            $handle = fopen($file, 'rb'); if ($handle === false) throw new \RuntimeException('Cannot verify compacted DataCore segment');
            try { while (!feof($handle)) { $document = $this->readOneRecord($handle); if (is_array($document)) $documents[] = $document; } }
            finally { fclose($handle); }
        }
        return $documents;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $file) { if (is_dir($file)) $this->removeDirectory($file); else @unlink($file); }
        @rmdir($directory);
    }

    private function segmentForId(string $id): int
    {
        return (int)(crc32($id) % 1000);
    }

    private function ensureCollectionIndexes(string $collection, bool $force = false): array
    {
        if (!$force && ($this->indexesReady[$collection] ?? false)) {
            return ['collection' => $collection, 'ready' => true, 'rebuilt' => false];
        }

        $marker = $this->indexMarker($collection);
        if (!$force && is_file($marker)) {
            $state = PhpSerializer::decode(file_get_contents($marker));
            if (is_array($state) && (int)($state['version'] ?? 0) === self::INDEX_VERSION) {
                $this->indexesReady[$collection] = true;
                return $state + ['ready' => true, 'rebuilt' => false];
            }
        }

        $lockPath = "{$this->basePath}/index/{$collection}.rebuild.lock";
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false) throw new \RuntimeException('Cannot open DataCore index rebuild lock');
        try {
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Cannot lock DataCore index rebuild');
            if (!$force && is_file($marker)) {
                $state = PhpSerializer::decode(file_get_contents($marker));
                if (is_array($state) && (int)($state['version'] ?? 0) === self::INDEX_VERSION) {
                    $this->indexesReady[$collection] = true;
                    return $state + ['ready' => true, 'rebuilt' => false];
                }
            }

            $this->ensureIndexDirectories($collection);
            $this->clearCollectionIndexFiles($collection);
            $latest = $this->scanLatestPointers($collection);
            $active = 0;
            foreach ($latest as $id => $entry) {
                $this->writePointer($collection, (string)$id, (int)$entry['segment'], (int)$entry['offset']);
                if (($entry['doc']['_deleted'] ?? false) !== true) {
                    $this->appendSearchPostings($collection, (string)$id, $entry['doc']);
                    $active++;
                }
            }

            $state = [
                'version' => self::INDEX_VERSION,
                'collection' => $collection,
                'documents' => $active,
                'pointers' => count($latest),
                'built_at' => microtime(true),
                'ready' => true,
                'rebuilt' => true,
            ];
            if (file_put_contents($marker, PhpSerializer::encode($state), LOCK_EX) === false) {
                throw new \RuntimeException('Cannot publish DataCore search index');
            }
            $this->indexesReady[$collection] = true;
            $this->documentCache = [];
            return $state;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string,array{segment:int,offset:int,doc:array}> */
    private function scanLatestPointers(string $collection): array
    {
        $latest = [];
        foreach (glob("{$this->basePath}/data/{$collection}_*.bin") ?: [] as $file) {
            if (preg_match('/_(\d+)\.bin$/', $file, $match) !== 1) continue;
            $segment = (int)$match[1];
            $handle = fopen($file, 'rb');
            if ($handle === false) continue;
            flock($handle, LOCK_SH);
            try {
                while (!feof($handle)) {
                    $offset = ftell($handle);
                    if ($offset === false) break;
                    $doc = $this->readOneRecord($handle);
                    if (!is_array($doc)) continue;
                    $id = (string)($doc['id'] ?? '');
                    if ($id !== '') {
                        $latest[$id] = ['segment' => $segment, 'offset' => (int)$offset, 'doc' => $doc];
                    }
                }
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
        return $latest;
    }

    private function writePointer(string $collection, string $id, int $segment, int $offset): void
    {
        $this->ensureIndexDirectories($collection);
        $pointer = ['id' => $id, 'segment' => $segment, 'offset' => $offset];
        $file = $this->pointerFile($collection, $id);
        if (file_put_contents($file, PhpSerializer::encode($pointer) . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write DataCore direct pointer');
        }
        $this->pointerCache[$collection . ':' . $id] = $pointer;
        if (isset($this->pointerShardCache[$file])) {
            $this->pointerShardCache[$file][$id] = $pointer;
        }
    }

    private function readPointer(string $collection, string $id): ?array
    {
        $cacheKey = $collection . ':' . $id;
        if (isset($this->pointerCache[$cacheKey])) return $this->pointerCache[$cacheKey];
        $file = $this->pointerFile($collection, $id);
        if (!is_file($file)) return null;
        if (!isset($this->pointerShardCache[$file])) {
            $pointers = [];
            $handle = fopen($file, 'rb');
            if ($handle === false) return null;
            flock($handle, LOCK_SH);
            try {
                while (($line = fgets($handle)) !== false) {
                    $pointer = PhpSerializer::decode(trim($line));
                    if (!is_array($pointer)) continue;
                    $pointerId = (string)($pointer['id'] ?? '');
                    if ($pointerId !== '' && isset($pointer['segment'], $pointer['offset'])) {
                        $pointers[$pointerId] = $pointer;
                    }
                }
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
            $this->pointerShardCache[$file] = $pointers;
        }
        $pointer = $this->pointerShardCache[$file][$id] ?? null;
        if (!is_array($pointer)) return null;
        return $this->pointerCache[$cacheKey] = $pointer;
    }

    private function appendSearchPostings(string $collection, string $id, array $doc): void
    {
        $timestamp = (int)($doc['_ts'] ?? time());
        foreach ($this->documentIndexKeys($doc) as $indexKey) {
            $line = hash('sha256', $indexKey) . ':' . $timestamp . ':' . rawurlencode($id) . "\n";
            $file = $this->postingFile($collection, $indexKey);
            $dir = dirname($file);
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create DataCore posting directory');
            }
            if (file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException('Cannot append DataCore search posting');
            }
        }
    }

    /** @return array<string,int> */
    private function queryIndexKeys(string $query): array
    {
        $keys = [];
        foreach ($this->tokenize($query) as $token) {
            $keys['e:' . $token] = 3;
            if (strlen($token) >= 3) {
                $keys['p:' . substr($token, 0, min(6, strlen($token)))] = 1;
            }
        }
        return $keys;
    }

    /** @return list<string> */
    private function documentIndexKeys(array $doc): array
    {
        $keys = [];
        foreach ($this->tokenize($this->indexableText($doc)) as $token) {
            $keys['e:' . $token] = true;
            if (strlen($token) >= 3) {
                $keys['p:' . substr($token, 0, min(6, strlen($token)))] = true;
            }
            if (count($keys) >= self::MAX_INDEX_KEYS) break;
        }
        return array_keys($keys);
    }

    /** @return list<string> */
    private function tokenize(string $text): array
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = strtr($text, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
        $parts = preg_split('/[^\p{L}\p{N}_-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = array_flip([
            'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'how', 'i', 'in', 'is', 'it',
            'me', 'my', 'of', 'on', 'or', 'the', 'to', 'was', 'what', 'when', 'where', 'who', 'with', 'you',
            'al', 'como', 'con', 'de', 'del', 'el', 'ella', 'en', 'es', 'esta', 'este', 'la', 'las', 'lo',
            'los', 'me', 'mi', 'o', 'para', 'por', 'que', 'se', 'su', 'un', 'una', 'y', 'yo',
        ]);
        $tokens = [];
        foreach ($parts as $part) {
            $token = trim((string)$part, '_-');
            if ($token === '' || strlen($token) > 64 || isset($stop[$token])) continue;
            $tokens['t:' . $token] = $token;
            if (count($tokens) >= 128) break;
        }
        return array_values($tokens);
    }

    private function indexableText(mixed $value, string $key = ''): string
    {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $childKey => $item) {
                $childKey = (string)$childKey;
                if ($childKey === 'id' || str_starts_with($childKey, '_')) continue;
                $parts[] = $this->indexableText($item, $childKey);
            }
            return implode(' ', array_filter($parts, static fn(string $part): bool => $part !== ''));
        }
        return is_scalar($value) ? (string)$value : '';
    }

    /** @return list<string> */
    private function postingLines(string $file, string $indexKey, int $limit): array
    {
        $expectedHash = hash('sha256', $indexKey);
        $source = str_contains($file, '/numeric/')
            ? array_reverse($this->readAllLines($file))
            : $this->tailLines($file, $limit);
        $matches = [];
        foreach ($source as $line) {
            if (!str_starts_with($line, $expectedHash . ':')) continue;
            $matches[] = $line;
            if (count($matches) >= $limit) break;
        }
        return $matches;
    }

    /** @return list<string> */
    private function readAllLines(string $file): array
    {
        if (!is_file($file)) return [];
        $handle = fopen($file, 'rb');
        if ($handle === false) return [];
        flock($handle, LOCK_SH);
        try {
            $lines = [];
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line !== '') $lines[] = $line;
            }
            return $lines;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return list<string> */
    private function tailLines(string $file, int $limit): array
    {
        if (!is_file($file) || $limit < 1) return [];
        $handle = fopen($file, 'rb');
        if ($handle === false) return [];
        flock($handle, LOCK_SH);
        try {
            fseek($handle, 0, SEEK_END);
            $position = ftell($handle);
            if ($position === false || $position === 0) return [];
            $buffer = '';
            $lines = [];
            while ($position > 0 && count($lines) < $limit) {
                $read = min(8192, $position);
                $position -= $read;
                fseek($handle, $position);
                $chunk = fread($handle, $read);
                if ($chunk === false) break;
                $buffer = $chunk . $buffer;
                $parts = explode("\n", $buffer);
                $buffer = array_shift($parts) ?? '';
                foreach (array_reverse($parts) as $line) {
                    if ($line === '') continue;
                    $lines[] = $line;
                    if (count($lines) >= $limit) break;
                }
            }
            if ($position === 0 && $buffer !== '' && count($lines) < $limit) $lines[] = $buffer;
            return $lines;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function pointerFile(string $collection, string $id): string
    {
        $hash = hash('sha256', $id);
        return "{$this->basePath}/index/lookup/{$collection}/" . substr($hash, 0, 2) . '.ptrlog';
    }

    private function postingFile(string $collection, string $indexKey): string
    {
        $hash = hash('sha256', $indexKey);
        $token = substr($indexKey, 2);
        if ($token !== '' && ctype_digit($token)) {
            return "{$this->basePath}/index/terms/{$collection}/numeric/" . substr($hash, 0, 2) . '.post';
        }
        return "{$this->basePath}/index/terms/{$collection}/" . substr($hash, 0, 2) . "/{$hash}.post";
    }

    private function indexMarker(string $collection): string
    {
        return "{$this->basePath}/index/terms/{$collection}/.ready";
    }

    private function ensureIndexDirectories(string $collection): void
    {
        foreach ([
            "{$this->basePath}/index/lookup/{$collection}",
            "{$this->basePath}/index/terms/{$collection}",
        ] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create DataCore index directory');
            }
        }
    }

    private function clearCollectionIndexFiles(string $collection): void
    {
        foreach (glob("{$this->basePath}/index/lookup/{$collection}/*.ptr") ?: [] as $file) @unlink($file);
        foreach (glob("{$this->basePath}/index/lookup/{$collection}/*.ptrlog") ?: [] as $file) @unlink($file);
        foreach (glob("{$this->basePath}/index/terms/{$collection}/*/*.post") ?: [] as $file) @unlink($file);
        $this->pointerCache = [];
        $this->pointerShardCache = [];
    }

    private function sanitizeCollection(string $collection): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', $collection) ?: 'memories';
        return trim($clean, '_') !== '' ? $clean : 'memories';
    }

    private function assertValidId(string $id): void
    {
        if ($id === '' || strlen($id) > 255 || preg_match('/[\x00-\x1F\x7F]/', $id) === 1) {
            throw new \InvalidArgumentException('DataCore id must contain 1-255 printable bytes');
        }
    }

    private function writeAll($handle, string $data, string $path): void
    {
        $written = 0;
        $length = strlen($data);
        while ($written < $length) {
            $bytes = fwrite($handle, substr($data, $written));
            if ($bytes === false || $bytes === 0) {
                throw new \RuntimeException("Cannot write DataCore segment: {$path}");
            }
            $written += $bytes;
        }
    }
}
