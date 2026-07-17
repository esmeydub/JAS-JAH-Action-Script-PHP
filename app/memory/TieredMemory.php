<?php

declare(strict_types=1);

namespace Jah\Memory;

use Jah\DataCore\DataCoreTurbo;
use Jah\DataCore\MemoryPyramid;
use Jah\DataCore\Compressor;
use Jah\DataCore\PhpSerializer;

/**
 * TieredMemory
 * Pure PHP wrapper over DataCoreTurbo + MemoryPyramid.
 * Keeps compatibility with both call styles used in the original project:
 * - store($id, $content, $tier)
 * - store($tier, $key, $data)
 */
class TieredMemory
{
    private DataCoreTurbo $hot;
    private MemoryPyramid $pyramid;
    private string $storagePath;
    private string $pyramidPath;
    private string $runtimeMemoryPath;
    private array $lastSearchMetrics = [];

    private const HOT_TTL = 3600;
    private const WARM_TTL = 604800;

    public function __construct(string $storagePath, string|array $hotStoragePath = '')
    {
        if (is_array($hotStoragePath)) {
            // Legacy constructor compatibility: base path + tier config.
            $base = rtrim($storagePath, '/');
            $this->storagePath = $base . '/datacore';
            $this->pyramidPath = $base . '/pyramid';
        } else {
            $this->storagePath = rtrim($storagePath, '/');
            $this->pyramidPath = rtrim($hotStoragePath !== '' ? $hotStoragePath : dirname($this->storagePath) . '/pyramid', '/');
        }

        $this->runtimeMemoryPath = dirname($this->storagePath);
        $this->hot = new DataCoreTurbo($this->storagePath, 500);
        $this->pyramid = new MemoryPyramid($this->pyramidPath);

        foreach (['warm', 'cold'] as $dir) {
            $path = $this->runtimeMemoryPath . '/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0700, true);
            }
        }
    }

    public function search(string $query, string|array $collectionOrTiers = 'memories', int $limit = 20): array
    {
        $startedAt = hrtime(true);
        $collection = $this->normalizeCollection(is_array($collectionOrTiers) ? 'memories' : $collectionOrTiers);
        $queryLower = $this->normalizeSearchText($query);
        $terms = preg_split('/[^\p{L}\p{N}_-]+/u', $queryLower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $candidateLimit = max(50, min(300, $limit * 5));
        $candidates = $this->hot->searchIndexed($collection, $queryLower, $candidateLimit);

        $allResults = [];
        foreach ($candidates as $doc) {
            if (($doc['_deleted'] ?? false) === true
                || ($doc['role'] ?? '') === 'assistant'
                || str_starts_with((string)($doc['_memory_kind'] ?? ''), 'conversation_')
                || $this->isExpiredWarm($doc)) continue;
            if (!$this->matches($doc, $queryLower, $terms)) continue;
            $doc['_memory_tier'] = $this->normalizeTier((string)($doc['_tier'] ?? 'hot'));
            $allResults[] = $doc;
        }

        usort($allResults, static function (array $a, array $b): int {
            $score = (int)($b['_search_score'] ?? 0) <=> (int)($a['_search_score'] ?? 0);
            return $score !== 0 ? $score : ((int)($b['_ts'] ?? 0) <=> (int)($a['_ts'] ?? 0));
        });

        $results = array_slice($allResults, 0, $limit);
        $this->lastSearchMetrics = [
            'strategy' => 'datacore_inverted_index_v3',
            'collection' => $collection,
            'candidate_count' => count($candidates),
            'result_count' => count($results),
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 3),
        ];
        return $results;
    }

    public function getLastSearchMetrics(): array
    {
        return $this->lastSearchMetrics;
    }

    /**
     * Returns ordered recent dialogue without requiring keyword overlap.
     */
    public function conversationTurns(string $conversationId, string $collection = 'memories', ?int $limit = null): array
    {
        $warmState = $this->retrieve($this->conversationWarmStateId($conversationId), null, $collection);
        $hotState = $this->retrieve($this->conversationStateId($conversationId), null, $collection);
        $warmTurns = is_array($warmState['turns'] ?? null) ? $warmState['turns'] : [];
        $hotTurns = is_array($hotState['turns'] ?? null) ? $hotState['turns'] : [];
        $warmCutoff = microtime(true) - self::WARM_TTL;
        $warmTurns = array_values(array_filter($warmTurns, static function (mixed $turn) use ($warmCutoff): bool {
            return is_array($turn) && (float)($turn['at'] ?? 0) >= $warmCutoff;
        }));
        $warmTurns = array_map(static function (mixed $turn): mixed {
            if (is_array($turn)) $turn['_conversation_tier'] = 'warm';
            return $turn;
        }, $warmTurns);
        $hotTurns = array_map(static function (mixed $turn): mixed {
            if (is_array($turn)) $turn['_conversation_tier'] = 'hot';
            return $turn;
        }, $hotTurns);
        $turns = array_merge($warmTurns, $hotTurns);
        $turns = array_values(array_filter($turns, static function (mixed $turn): bool {
            return is_array($turn)
                && in_array((string)($turn['role'] ?? ''), ['user', 'assistant'], true)
                && trim((string)($turn['content'] ?? '')) !== '';
        }));
        if ($limit !== null && $limit > 0) {
            return array_slice($turns, -$limit);
        }
        return $turns;
    }

    /**
     * Appends one completed exchange without deleting earlier Hot turns.
     */
    public function appendConversationExchange(
        string $conversationId,
        string $message,
        string $response,
        string $collection = 'memories',
        int $hotMaxChars = 8000
    ): array {
        $conversationId = $this->normalizeConversationId($conversationId);
        $collection = $this->normalizeCollection($collection);
        $message = trim($message);
        $response = trim($response);
        if ($message === '' || $response === '') {
            throw new \InvalidArgumentException('Conversation exchange requires user and assistant content');
        }

        $lockDirectory = $this->runtimeMemoryPath . '/conversation-locks';
        if (!is_dir($lockDirectory) && !mkdir($lockDirectory, 0700, true) && !is_dir($lockDirectory)) {
            throw new \RuntimeException('Cannot create conversation lock directory');
        }
        $lockPath = $lockDirectory . '/' . hash('sha256', $collection . ':' . $conversationId) . '.lock';
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new \RuntimeException('Cannot lock conversation memory');
        }

        try {
            $id = $this->conversationStateId($conversationId);
            $current = $this->retrieve($id, null, $collection);
            $turns = is_array($current['turns'] ?? null) ? $current['turns'] : [];
            $now = microtime(true);
            $turns[] = ['role' => 'user', 'content' => $message, 'at' => $now];
            $turns[] = ['role' => 'assistant', 'content' => $response, 'at' => $now + 0.000001];

            $warmId = $this->conversationWarmStateId($conversationId);
            $warmState = $this->retrieve($warmId, null, $collection);
            $warmTurns = is_array($warmState['turns'] ?? null) ? $warmState['turns'] : [];
            $warmCutoff = microtime(true) - self::WARM_TTL;
            $warmTurns = array_values(array_filter($warmTurns, static function (mixed $turn) use ($warmCutoff): bool {
                return is_array($turn) && (float)($turn['at'] ?? 0) >= $warmCutoff;
            }));
            $hotMaxChars = max(2000, $hotMaxChars);
            while (count($turns) > 2 && $this->conversationChars($turns) > $hotMaxChars) {
                $warmTurns[] = array_shift($turns);
                if ($turns !== []) $warmTurns[] = array_shift($turns);
            }

            if ($warmTurns !== []) {
                $this->store($warmId, [
                    'role' => 'conversation',
                    'content' => 'long conversation history',
                    'conversation_id' => $conversationId,
                    'turns' => $warmTurns,
                    '_memory_kind' => 'conversation_warm',
                    '_ts' => time(),
                ], 'warm', $collection);
            }
            $this->store($id, [
                'role' => 'conversation',
                'content' => 'recent conversation context',
                'conversation_id' => $conversationId,
                'turns' => $turns,
                '_memory_kind' => 'conversation_state',
                '_ts' => time(),
            ], 'hot', $collection);

            return array_merge($warmTurns, $turns);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function store(string $first, mixed $second, mixed $third = 'hot', string $collection = 'memories'): bool
    {
        $collection = $this->normalizeCollection($collection);
        if (is_string($second) && is_array($third)) {
            // Legacy style: store($tier, $key, $data)
            $tier = $this->normalizeTier($first);
            $id = $second;
            $content = $third;
        } else {
            // Current style: store($id, $content, $tier)
            $id = $first;
            $content = is_array($second) ? $second : ['content' => (string)$second];
            $tier = $this->normalizeTier(is_string($third) ? $third : 'hot');
        }

        $content['id'] = $id;
        $content['_ts'] = $content['_ts'] ?? time();
        $content['_tier'] = $tier;
        $content['_collection'] = $collection;

        $this->hot->insert($collection, $content);
        $this->hot->flush();
        $this->pyramid->set($this->storageKey($collection, $id), $content, 0, $tier);

        if ($tier === 'warm') {
            $this->appendWarm($id, $content);
        } elseif ($tier === 'cold') {
            $this->appendCold($id, $content);
        }

        return true;
    }

    public function retrieve(string $tierOrId, ?string $key = null, string $collection = 'memories'): ?array
    {
        $collection = $this->normalizeCollection($collection);
        $id = $key ?? $tierOrId;
        $fromTurbo = $this->hot->find($collection, $id);
        if ($fromTurbo !== null) {
            return $this->isExpiredWarm($fromTurbo) ? null : $fromTurbo;
        }

        $fromPyramid = $this->pyramid->get($this->storageKey($collection, $id));
        if ($fromPyramid === null && $collection === 'memories') {
            $fromPyramid = $this->pyramid->get($id);
        }
        if (is_array($fromPyramid) && ($fromPyramid['_deleted'] ?? false) === true) {
            return null;
        }
        return is_array($fromPyramid) && !$this->isExpiredWarm($fromPyramid) ? $fromPyramid : null;
    }

    public function forget(string $id, string $collection = 'memories'): void
    {
        $collection = $this->normalizeCollection($collection);
        $this->hot->delete($collection, $id);
        $this->pyramid->set(
            $this->storageKey($collection, $id),
            ['id' => $id, '_collection' => $collection, '_deleted' => true, '_ts' => time()],
            0,
            'warm'
        );
    }

    public function migrate(string $collection = 'memories'): array
    {
        $collection = $this->normalizeCollection($collection);
        $migrated = ['hot_to_warm' => 0, 'warm_expired' => 0];
        $now = time();

        $allDocs = $this->hot->query($collection, static fn(array $doc): bool => ($doc['_deleted'] ?? false) !== true);

        foreach ($allDocs as $doc) {
            $id = (string)($doc['id'] ?? '');
            if ($id === '') continue;

            $ts = (int)($doc['_ts'] ?? $now);
            $currentTier = $this->normalizeTier((string)($doc['_tier'] ?? 'hot'));
            $age = $now - $ts;
            $kind = (string)($doc['_memory_kind'] ?? '');
            // Conversation tiers are semantic: active dialogue stays Hot,
            // long dialogue history stays Warm for seven days, and Cold is
            // permanent. Conversation history enforces its TTL per turn.
            if (str_starts_with($kind, 'conversation_')) {
                continue;
            }

            if ($currentTier === 'hot' && $age > self::HOT_TTL) {
                $doc['_tier'] = 'warm';
                $this->appendWarm($id, $doc);
                $this->pyramid->set($this->storageKey($collection, $id), $doc, 0, 'warm');
                $this->hot->insert($collection, $doc);
                $migrated['hot_to_warm']++;
            } elseif ($currentTier === 'warm' && $age > self::WARM_TTL) {
                $this->forget($id, $collection);
                $migrated['warm_expired']++;
            }
        }

        $this->hot->flush();
        return $migrated;
    }

    public function migrateTiers(): array
    {
        $summary = $this->migrate('memories');
        $events = [];
        foreach ($summary as $path => $count) {
            for ($i = 0; $i < $count; $i++) {
                if ($path === 'warm_expired') {
                    [$from, $to] = ['warm', 'expired'];
                } else {
                    [$from, $to] = explode('_to_', $path, 2);
                }
                $events[] = ['key' => 'memory', 'from' => $from, 'to' => $to];
            }
        }
        return $events;
    }

    public function stats(string $collection = 'memories'): array
    {
        $collection = $this->normalizeCollection($collection);
        return array_merge($this->pyramid->stats(), [
            'hot_documents' => $this->hot->getStats()['documents'] ?? 0,
            'warm_records' => $this->countSerializedLines($this->runtimeMemoryPath . '/warm'),
            'cold_files' => count(glob($this->runtimeMemoryPath . '/cold/*.jahp.gz') ?: []),
            'search_index' => $this->hot->getIndexStats($collection),
        ]);
    }

    public function rebuildIndexes(string $collection = 'memories'): array
    {
        return $this->hot->rebuildIndexes($this->normalizeCollection($collection));
    }

    public function close(): void
    {
        $this->hot->close();
    }

    private function appendWarm(string $id, array $doc): void
    {
        $dir = $this->runtimeMemoryPath . '/warm';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $file = $dir . '/warm_' . date('Ymd') . '.jahl';
        $record = PhpSerializer::encode(['key' => $id, 'payload' => $doc]);
        if ($record !== false) {
            file_put_contents($file, $record . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    private function appendCold(string $id, array $doc): void
    {
        $dir = $this->runtimeMemoryPath . '/cold';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $file = $dir . '/cold_' . hash('sha256', $id) . '_' . time() . '.jahp.gz';
        $payload = PhpSerializer::encode([$id => $doc]);
        if ($payload !== '') {
            file_put_contents($file, Compressor::compress($payload, 'gzip'), LOCK_EX);
        }
    }

    private function readWarmRecords(string $collection): array
    {
        $records = [];
        $dir = $this->runtimeMemoryPath . '/warm';
        foreach (glob($dir . '/*.jahl') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $record = PhpSerializer::decode($line, true);
                $doc = is_array($record) ? ($record['payload'] ?? null) : null;
                if (!is_array($doc)) continue;
                if ((string)($doc['_collection'] ?? 'memories') !== $collection) continue;
                $records[] = $doc;
            }
        }
        return $records;
    }

    private function readColdRecords(string $collection): array
    {
        $records = [];
        $dir = $this->runtimeMemoryPath . '/cold';
        foreach (glob($dir . '/*.jahp.gz') ?: [] as $file) {
            $tmp = tempnam(sys_get_temp_dir(), 'jah_cold_');
            if ($tmp === false) continue;
            $ok = Compressor::decompressFile($file, $tmp, 'gzip');
            $raw = $ok ? file_get_contents($tmp) : false;
            @unlink($tmp);
            $data = $raw !== false ? PhpSerializer::decode($raw, true) : null;
            if (!is_array($data)) continue;
            foreach ($data as $doc) {
                if (!is_array($doc)) continue;
                if ((string)($doc['_collection'] ?? 'memories') !== $collection) continue;
                $records[] = $doc;
            }
        }
        return $records;
    }

    private function matches(array $doc, string $queryLower, array $terms): bool
    {
        $searchable = $this->normalizeSearchText(PhpSerializer::searchable($doc));
        if ($queryLower !== '' && str_contains($searchable, $queryLower)) return true;
        foreach ($terms as $term) {
            if ($term !== '' && str_contains($searchable, $term)) return true;
        }
        return false;
    }

    private function normalizeSearchText(string $text): string
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        return strtr($text, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
    }

    private function countSerializedLines(string $dir): int
    {
        $count = 0;
        foreach (glob($dir . '/*.jahl') ?: [] as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $count += is_array($lines) ? count($lines) : 0;
        }
        return $count;
    }

    private function normalizeTier(string $tier): string
    {
        return in_array($tier, ['hot', 'warm', 'cold'], true) ? $tier : 'hot';
    }

    private function isExpiredWarm(array $document): bool
    {
        return ($document['_tier'] ?? '') === 'warm'
            && (int)($document['_ts'] ?? 0) > 0
            && (time() - (int)$document['_ts']) > self::WARM_TTL;
    }

    private function storageKey(string $collection, string $id): string
    {
        return $collection . ':' . $id;
    }

    private function normalizeCollection(string $collection): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', $collection) ?: 'memories';
        return trim($clean, '_') !== '' ? $clean : 'memories';
    }

    private function conversationStateId(string $conversationId): string
    {
        return 'conversation_' . substr(hash('sha256', $this->normalizeConversationId($conversationId)), 0, 32);
    }

    private function conversationWarmStateId(string $conversationId): string
    {
        return 'conversation_warm_' . substr(hash('sha256', $this->normalizeConversationId($conversationId)), 0, 32);
    }

    private function conversationChars(array $turns): int
    {
        $length = 0;
        foreach ($turns as $turn) {
            if (is_array($turn)) $length += strlen((string)($turn['content'] ?? ''));
        }
        return $length;
    }

    private function normalizeConversationId(string $conversationId): string
    {
        $conversationId = trim($conversationId);
        if ($conversationId === '' || strlen($conversationId) > 128 || preg_match('/^[a-zA-Z0-9_.-]+$/', $conversationId) !== 1) {
            throw new \InvalidArgumentException('Invalid conversation id');
        }
        return $conversationId;
    }
}
