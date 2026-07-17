<?php

declare(strict_types=1);

namespace Jah\DataCore;

use Jah\JAS\Security\DualControlStore;
use PDO;
use RuntimeException;

/**
 * Puente de adopción de una sola dirección: SQL no confiable -> contratos DataCore.
 * Sólo está disponible en modo explícito de migración y tras doble control.
 */
final class GovernedSqlImporter
{
    public const APPROVAL_ACTION = 'datacore.sql.governed-import';

    /** @var array<string,array{table:string,fields:array<string,string>,cursor:string}> */
    private array $mappings = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly DataCoreDatabase $database,
        private readonly DualControlStore $approvals,
        private readonly SqlMirrorMode $mode = SqlMirrorMode::DataCorePrimary,
        private readonly int $maximumValueBytes = 1_048_576,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        if ($maximumValueBytes < 1_024 || $maximumValueBytes > 16_777_216) {
            throw new RuntimeException('sql_import_value_limit_invalid');
        }
    }

    /** @param array<string,string> $fields SQL column => DataCore field */
    public function map(string $collection, string $table, array $fields, string $cursorColumn): self
    {
        $this->identifier($collection);
        $this->identifier($table);
        $this->identifier($cursorColumn);
        if ($fields === [] || !in_array('id', $fields, true) || !isset($fields[$cursorColumn])) {
            throw new RuntimeException('sql_import_mapping_invalid');
        }
        foreach ($fields as $column => $field) {
            $this->identifier((string) $column);
            $this->identifier((string) $field);
        }
        $this->mappings[$collection] = [
            'table' => $table,
            'fields' => $fields,
            'cursor' => $cursorColumn,
        ];
        return $this;
    }

    public function approvalFingerprint(
        string $collection,
        int|string|null $afterCursor,
        int $limit,
    ): string {
        $mapping = $this->mapping($collection);
        $this->assertWindow($afterCursor, $limit);
        return hash('sha256', PhpSerializer::encode([
            'mode' => $this->mode->value,
            'collection' => $collection,
            'mapping' => $mapping,
            'after_cursor' => $afterCursor,
            'limit' => $limit,
        ]));
    }

    /** @return array{imported:int,last_cursor:int|string|null,complete:bool} */
    public function import(
        string $approvalId,
        string $requestId,
        string $collection,
        int|string|null $afterCursor,
        int $limit = 500,
    ): array {
        if ($this->mode !== SqlMirrorMode::GovernedSqlMigration) {
            throw new RuntimeException('sql_import_disabled');
        }
        $mapping = $this->mapping($collection);
        $fingerprint = $this->approvalFingerprint($collection, $afterCursor, $limit);
        $this->approvals->consume($approvalId, self::APPROVAL_ACTION, $requestId, $fingerprint);

        $columns = array_keys($mapping['fields']);
        $quotedColumns = array_map(fn(string $column): string => $this->quote($column), $columns);
        $sql = 'SELECT ' . implode(', ', $quotedColumns)
            . ' FROM ' . $this->quote($mapping['table']);
        $parameters = [];
        if ($afterCursor !== null) {
            $sql .= ' WHERE ' . $this->quote($mapping['cursor']) . ' > ?';
            $parameters[] = $afterCursor;
        }
        $sql .= ' ORDER BY ' . $this->quote($mapping['cursor']) . ' ASC LIMIT ' . $limit;
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || count($rows) > $limit) throw new RuntimeException('sql_import_response_limit_exceeded');

        $documents = [];
        $lastCursor = $afterCursor;
        foreach ($rows as $row) {
            if (!is_array($row)) throw new RuntimeException('sql_import_row_invalid');
            $document = [];
            foreach ($mapping['fields'] as $column => $field) {
                if (!array_key_exists($column, $row)) throw new RuntimeException('sql_import_column_missing');
                $value = $row[$column];
                if (!is_null($value) && !is_scalar($value)) {
                    throw new RuntimeException('sql_import_scalar_value_required');
                }
                if (is_string($value) && strlen($value) > $this->maximumValueBytes) {
                    throw new RuntimeException('sql_import_value_too_large');
                }
                $document[$field] = $value;
            }
            $cursor = $row[$mapping['cursor']] ?? null;
            if (!is_int($cursor) && !is_string($cursor)) {
                throw new RuntimeException('sql_import_cursor_invalid');
            }
            $lastCursor = $cursor;
            $documents[] = $document;
        }

        // Ninguna fila entra si otra del mismo lote viola tipo, constraint o unicidad.
        $this->database->validateInsertDocuments($collection, $documents);
        foreach ($documents as $document) $this->database->insert($collection, $document);

        return [
            'imported' => count($documents),
            'last_cursor' => $lastCursor,
            'complete' => count($documents) < $limit,
        ];
    }

    private function mapping(string $collection): array
    {
        return $this->mappings[$collection] ?? throw new RuntimeException('sql_import_collection_not_allowed');
    }

    private function assertWindow(int|string|null $afterCursor, int $limit): void
    {
        if ($limit < 1 || $limit > 10_000) throw new RuntimeException('sql_import_window_invalid');
        if (is_string($afterCursor) && (strlen($afterCursor) > 255 || str_contains($afterCursor, "\0"))) {
            throw new RuntimeException('sql_import_cursor_invalid');
        }
    }

    private function quote(string $identifier): string
    {
        $this->identifier($identifier);
        return '`' . $identifier . '`';
    }

    private function identifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $identifier)) {
            throw new RuntimeException('sql_import_identifier_invalid');
        }
    }
}
