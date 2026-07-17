<?php

declare(strict_types=1);

namespace Jah\DataCore;

use PDO;
use RuntimeException;

final class PdoSqlMirror
{
    /** @var array<string,array{table:string,fields:array<string,string>}> */
    private array $schema = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?SqlMirrorAuditJournal $audit = null,
    )
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function map(string $collection, string $table, array $fields): self
    {
        $this->identifier($collection);
        $this->identifier($table);
        if ($fields === []) throw new RuntimeException('sql_mirror_fields_required');
        foreach ($fields as $source => $column) {
            $this->identifier((string) $source);
            $this->identifier((string) $column);
        }
        $this->schema[$collection] = ['table' => $table, 'fields' => $fields];
        return $this;
    }

    public function apply(array $entry): void
    {
        $collection = (string) ($entry['collection'] ?? '');
        $mapping = $this->schema[$collection] ?? throw new RuntimeException('sql_mirror_collection_not_allowed');
        $operation = (string) ($entry['operation'] ?? '');
        $id = (string) ($entry['document_id'] ?? '');
        $version = (int) ($entry['version'] ?? 0);
        if ($id === '' || $version < 1) throw new RuntimeException('sql_mirror_entry_invalid');

        $this->pdo->beginTransaction();
        try {
            if ($operation === 'upsert') {
                $this->upsert($mapping, $id, $version, (array) ($entry['projection'] ?? []));
            } elseif ($operation === 'delete') {
                $this->delete($mapping['table'], $id, $version);
            } else {
                throw new RuntimeException('sql_mirror_operation_invalid');
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function reconcile(array $entry): array
    {
        $collection = (string) ($entry['collection'] ?? '');
        $mapping = $this->schema[$collection] ?? throw new RuntimeException('sql_mirror_collection_not_allowed');
        $id = (string) ($entry['document_id'] ?? '');
        $version = (int) ($entry['version'] ?? 0);
        if ($id === '' || $version < 1) throw new RuntimeException('sql_mirror_entry_invalid');
        $selectedColumns = ['_datacore_version', '_datacore_hash', ...array_values($mapping['fields'])];
        $statement = $this->pdo->prepare(
            'SELECT ' . implode(', ', array_map(fn(string $column): string => $this->quote($column), $selectedColumns))
            . ' FROM ' . $this->quote($mapping['table']) . ' WHERE _datacore_id = ?',
        );
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return $this->audited([
            'status' => 'missing',
            'document_id' => $id,
            'datacore_version' => $version,
        ]);
        $sqlVersion = (int) ($row['_datacore_version'] ?? 0);
        $expectedHash = hash('sha256', PhpSerializer::encode((array) ($entry['projection'] ?? [])));
        $actualProjection = [];
        foreach ($mapping['fields'] as $source => $column) {
            if (array_key_exists($source, (array) ($entry['projection'] ?? []))) {
                $actualProjection[$source] = $row[$column] ?? null;
            }
        }
        $actualHash = hash('sha256', PhpSerializer::encode($actualProjection));
        $storedHash = (string) ($row['_datacore_hash'] ?? '');
        $status = match (true) {
            $sqlVersion > $version => 'sql_ahead_untrusted',
            $sqlVersion < $version => 'sql_behind',
            !hash_equals($expectedHash, $storedHash)
                || !hash_equals($expectedHash, $actualHash) => 'diverged',
            default => 'in_sync',
        };
        return $this->audited([
            'status' => $status,
            'document_id' => $id,
            'datacore_version' => $version,
            'sql_version' => $sqlVersion,
        ]);
    }

    private function upsert(array $mapping, string $id, int $version, array $projection): void
    {
        $columns = ['_datacore_id', '_datacore_version', '_datacore_hash'];
        $values = [$id, $version, hash('sha256', PhpSerializer::encode($projection))];
        foreach ($mapping['fields'] as $source => $column) {
            if (!array_key_exists($source, $projection)) continue;
            $value = $projection[$source];
            if (!is_null($value) && !is_scalar($value)) {
                throw new RuntimeException('sql_mirror_scalar_projection_required');
            }
            $columns[] = $column;
            $values[] = $value;
        }
        $quoted = array_map(fn(string $column): string => $this->quote($column), $columns);
        $parameters = implode(', ', array_fill(0, count($columns), '?'));
        $updates = array_map(
            fn(string $column): string => $this->quote($column) . '=excluded.' . $this->quote($column),
            array_slice($columns, 1),
        );
        $sql = 'INSERT INTO ' . $this->quote($mapping['table'])
            . ' (' . implode(', ', $quoted) . ') VALUES (' . $parameters . ') '
            . 'ON CONFLICT(_datacore_id) DO UPDATE SET ' . implode(', ', $updates)
            . ' WHERE excluded._datacore_version >= _datacore_version';
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $updates = array_map(
                fn(string $column): string => $this->quote($column)
                    . '=IF(VALUES(_datacore_version)>=_datacore_version,VALUES('
                    . $this->quote($column) . '),' . $this->quote($column) . ')',
                array_slice($columns, 1),
            );
            $sql = 'INSERT INTO ' . $this->quote($mapping['table'])
                . ' (' . implode(', ', $quoted) . ') VALUES (' . $parameters . ') '
                . 'ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);
    }

    private function delete(string $table, string $id, int $version): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM ' . $this->quote($table)
            . ' WHERE _datacore_id = ? AND _datacore_version <= ?',
        );
        $statement->execute([$id, $version]);
    }

    private function quote(string $identifier): string
    {
        $this->identifier($identifier);
        return '`' . $identifier . '`';
    }

    private function identifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $identifier)) {
            throw new RuntimeException('sql_mirror_identifier_invalid');
        }
    }

    private function audited(array $result): array
    {
        $this->audit?->record($result);
        return $result;
    }
}
