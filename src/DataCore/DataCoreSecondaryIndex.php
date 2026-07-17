<?php

declare(strict_types=1);

namespace Jah\DataCore;

use RuntimeException;

final class DataCoreSecondaryIndex
{
    /** @var array<string,array<string,list<string>>> */
    private array $definitions = [];
    /** @var array<string,array<string,callable>> */
    private array $predicates = [];
    /** @var array<string,array<string,string>> */
    private array $rangeDefinitions = [];
    /** @var array<string,array<string,string>> */
    private array $writeOverrides = [];
    /** @var array<string,array{signature:string,entries:list<array>}> */
    private array $entryCache = [];

    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('datacore_secondary_index_directory_failed');
        }
    }

    public function define(string $collection, string $name, array $fields, ?callable $predicate = null): void
    {
        $this->definitions[$collection][$name] = array_values($fields);
        if ($predicate !== null) $this->predicates[$collection][$name] = $predicate;
    }

    public function defineRange(string $collection, string $name, string $field): void
    {
        $this->rangeDefinitions[$collection][$name] = $field;
    }

    public function record(
        string $collection,
        string $id,
        array $document,
        ?string $transactionId = null,
        bool $deleted = false,
    ): void {
        foreach ($this->definitions[$collection] ?? [] as $name => $fields) {
            $included = $deleted || !isset($this->predicates[$collection][$name])
                || ($this->predicates[$collection][$name])($document) === true;
            $values = [];
            foreach ($fields as $field) {
                if (!array_key_exists($field, $document)) {
                    if (!$deleted) continue 2;
                    $values[$field] = null;
                } else {
                    $values[$field] = $document[$field];
                }
            }
            $this->append($collection, $name, [
                'id' => $id,
                'key' => ($deleted || !$included) ? null : $this->key($values),
                'transaction_id' => $transactionId,
                'deleted' => $deleted || !$included,
                'at' => microtime(true),
            ]);
        }
        foreach ($this->rangeDefinitions[$collection] ?? [] as $name => $field) {
            $value = $document[$field] ?? null;
            if (!$deleted && !is_int($value) && !is_float($value)) {
                throw new RuntimeException('datacore_range_value_invalid:' . $name);
            }
            $this->append($collection, $name, [
                'id' => $id,
                'range' => $deleted ? null : $value,
                'transaction_id' => $transactionId,
                'deleted' => $deleted,
                'at' => microtime(true),
            ]);
        }
    }

    public function exact(
        string $collection,
        string $name,
        array $values,
        callable $transactionCommitted,
    ): array {
        $fields = $this->definitions[$collection][$name]
            ?? throw new RuntimeException('datacore_index_not_defined');
        if (array_keys($values) !== $fields) {
            throw new RuntimeException('datacore_index_values_invalid');
        }
        $wanted = $this->key($values);
        $latest = [];
        foreach ($this->entries($collection, $name) as $entry) {
            $transactionId = $entry['transaction_id'] ?? null;
            $visible = !is_string($transactionId)
                || $transactionId === ''
                || $transactionCommitted($transactionId) === true;
            if ($visible && isset($entry['id'])) $latest[(string) $entry['id']] = $entry;
        }
        $ids = [];
        foreach ($latest as $id => $entry) {
            if (($entry['deleted'] ?? false) !== true && hash_equals($wanted, (string) ($entry['key'] ?? ''))) {
                $ids[] = $id;
            }
        }
        sort($ids, SORT_STRING);
        return $ids;
    }

    public function range(
        string $collection,
        string $name,
        int|float|null $minimum,
        int|float|null $maximum,
        callable $transactionCommitted,
    ): array {
        if (!isset($this->rangeDefinitions[$collection][$name])) {
            throw new RuntimeException('datacore_index_not_defined');
        }
        if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
            throw new RuntimeException('datacore_range_invalid');
        }
        $latest = [];
        foreach ($this->entries($collection, $name) as $entry) {
            $transactionId = $entry['transaction_id'] ?? null;
            $visible = !is_string($transactionId)
                || $transactionId === ''
                || $transactionCommitted($transactionId) === true;
            if ($visible && isset($entry['id'])) $latest[(string) $entry['id']] = $entry;
        }
        $matches = [];
        foreach ($latest as $id => $entry) {
            if (($entry['deleted'] ?? false) === true) continue;
            $value = $entry['range'] ?? null;
            if (!is_int($value) && !is_float($value)) continue;
            if ($minimum !== null && $value < $minimum) continue;
            if ($maximum !== null && $value > $maximum) continue;
            $matches[$id] = $value;
        }
        asort($matches, SORT_NUMERIC);
        return array_keys($matches);
    }

    public function rebuild(string $collection, array $documents): array
    {
        $names = array_values(array_unique(array_merge(
            array_keys($this->definitions[$collection] ?? []),
            array_keys($this->rangeDefinitions[$collection] ?? []),
        )));
        $temporaryFiles = [];
        try {
            foreach ($names as $name) {
                $temporary = $this->indexPath($collection, $name) . '.tmp.' . bin2hex(random_bytes(4));
                $handle = fopen($temporary, 'xb');
                if ($handle === false) throw new RuntimeException('datacore_reindex_create_failed');
                fclose($handle);
                @chmod($temporary, 0600);
                $temporaryFiles[$name] = $temporary;
                $this->writeOverrides[$collection][$name] = $temporary;
            }
            foreach ($documents as $id => $document) {
                if (is_array($document)) $this->record($collection, (string) $id, $document);
            }
            foreach ($temporaryFiles as $name => $temporary) {
                if (!rename($temporary, $this->indexPath($collection, $name))) {
                    throw new RuntimeException('datacore_reindex_publish_failed');
                }
                unset($this->entryCache[$this->indexPath($collection, $name)]);
            }
        } finally {
            unset($this->writeOverrides[$collection]);
            foreach ($temporaryFiles as $temporary) {
                if (is_file($temporary)) @unlink($temporary);
            }
        }
        return ['collection' => $collection, 'indexes' => count($names), 'documents' => count($documents)];
    }

    private function append(string $collection, string $name, array $entry): void
    {
        $path = $this->path($collection, $name);
        $line = PhpSerializer::encode($entry) . "\n";
        if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new RuntimeException('datacore_secondary_index_write_failed');
        }
        @chmod($path, 0600);
        unset($this->entryCache[$path]);
    }

    private function entries(string $collection, string $name): array
    {
        $path = $this->path($collection, $name);
        if (!is_file($path)) return [];
        clearstatcache(true, $path);
        $stat = stat($path);
        if (!is_array($stat)) throw new RuntimeException('datacore_secondary_index_stat_failed');
        $signature = (string) $stat['ino'] . ':' . (string) $stat['size'] . ':' . (string) $stat['mtime'];
        if (($this->entryCache[$path]['signature'] ?? null) === $signature) {
            return $this->entryCache[$path]['entries'];
        }
        $entries = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = PhpSerializer::decode($line);
            if (!is_array($entry)) throw new RuntimeException('datacore_secondary_index_corrupt');
            $entries[] = $entry;
        }
        $this->entryCache[$path] = ['signature' => $signature, 'entries' => $entries];
        return $entries;
    }

    private function path(string $collection, string $name): string
    {
        if (isset($this->writeOverrides[$collection][$name])) {
            return $this->writeOverrides[$collection][$name];
        }
        return $this->indexPath($collection, $name);
    }

    private function indexPath(string $collection, string $name): string
    {
        return rtrim($this->directory, '/') . '/' . $collection . '--' . $name . '.jahi';
    }

    private function key(array $values): string
    {
        return hash('sha256', PhpSerializer::encode($values));
    }
}
