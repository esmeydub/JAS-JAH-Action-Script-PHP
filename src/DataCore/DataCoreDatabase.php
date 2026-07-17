<?php

declare(strict_types=1);

namespace Jah\DataCore;

use Jah\JAS\Type\TypeRegistry;
use Jah\JAS\Security\KeyRing;
use RuntimeException;

final class DataCoreDatabase
{
    /** @var array<string,string> */
    private array $collections = [];
    /** @var array<string,list<string>> */
    private array $encryptedFields = [];
    /** @var array<string,array<string,list<string>>> */
    private array $uniqueIndexes = [];
    /** @var array<string,array<string,callable>> */
    private array $constraints = [];
    /** @var array<string,array<string,array{field:string,target:string}>> */
    private array $references = [];
    /** @var array<string,array{subject_field:string,fields:list<string>,vault:SubjectKeyVault}> */
    private array $subjectEncryption = [];
    /** @var array<string,array{outbox:SqlMirrorOutbox,fields:list<string>}> */
    private array $sqlMirrors = [];
    private string $lockDirectory;
    private KeyRing $keyRing;
    private DataCoreSecondaryIndex $secondaryIndexes;
    /** @var null|callable(string):bool */
    private mixed $transactionCommitted = null;
    private array $lastQueryPlan = [];

    public function __construct(private readonly DataCoreTurbo $storage, private readonly TypeRegistry $types, string $runtimeDirectory, string|KeyRing $masterKey)
    {
        if (!extension_loaded('sodium')) throw new RuntimeException('datacore_sodium_required');
        if (is_string($masterKey) && strlen($masterKey) < 32) throw new RuntimeException('datacore_master_key_invalid');
        $this->keyRing = $masterKey instanceof KeyRing ? $masterKey : new KeyRing(['legacy' => $masterKey], 'legacy');
        $this->lockDirectory = rtrim($runtimeDirectory, '/') . '/locks';
        $this->secondaryIndexes = new DataCoreSecondaryIndex(
            rtrim($runtimeDirectory, '/') . '/indexes',
        );
        if (
            !is_dir($this->lockDirectory)
            && !mkdir($this->lockDirectory, 0700, true)
            && !is_dir($this->lockDirectory)
        ) {
            throw new RuntimeException('datacore_lock_directory_failed');
        }
    }

    public function encryptFields(string $collection, array $fields): self
    {
        $this->typeFor($collection);
        foreach ($fields as $field) if (!is_string($field) || !preg_match('/^[a-z_][a-z0-9_]{0,127}$/i', $field)) throw new RuntimeException('datacore_encrypted_field_invalid');
        $this->encryptedFields[$collection] = array_values(array_unique($fields));
        return $this;
    }

    public function encryptFieldsBySubject(
        string $collection,
        string $subjectField,
        array $fields,
        SubjectKeyVault $vault,
    ): self {
        $this->typeFor($collection);
        if (!preg_match('/^[a-z_][a-z0-9_]{0,127}$/i', $subjectField) || $fields === []) {
            throw new RuntimeException('datacore_subject_encryption_definition_invalid');
        }
        foreach ($fields as $field) {
            if (!is_string($field) || !preg_match('/^[a-z_][a-z0-9_]{0,127}$/i', $field)) {
                throw new RuntimeException('datacore_encrypted_field_invalid');
            }
            if ($field === $subjectField || in_array($field, $this->encryptedFields[$collection] ?? [], true)) {
                throw new RuntimeException('datacore_subject_encryption_field_conflict');
            }
        }
        $this->subjectEncryption[$collection] = [
            'subject_field' => $subjectField,
            'fields' => array_values(array_unique($fields)),
            'vault' => $vault,
        ];
        return $this;
    }

    public function collection(string $name, string $documentType): self
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $name)) throw new RuntimeException('datacore_collection_invalid');
        if (!$this->types->has($documentType)) throw new RuntimeException('datacore_document_type_not_defined');
        if (isset($this->collections[$name])) throw new RuntimeException('datacore_collection_already_defined');
        $this->collections[$name] = $documentType;
        return $this;
    }

    public function uniqueIndex(string $collection, string $name, array $fields): self
    {
        $this->typeFor($collection);
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $name)) {
            throw new RuntimeException('datacore_index_name_invalid');
        }
        if ($fields === []) throw new RuntimeException('datacore_index_fields_required');
        foreach ($fields as $field) {
            if (!is_string($field) || !preg_match('/^[a-z_][a-z0-9_]{0,127}$/i', $field)) {
                throw new RuntimeException('datacore_index_field_invalid');
            }
        }
        if (isset($this->uniqueIndexes[$collection][$name])) {
            throw new RuntimeException('datacore_index_already_defined');
        }
        $this->uniqueIndexes[$collection][$name] = array_values(array_unique($fields));
        $this->secondaryIndexes->define($collection, $name, $fields);
        return $this;
    }

    public function constraint(string $collection, string $name, callable $rule): self
    {
        $this->typeFor($collection);
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $name)) {
            throw new RuntimeException('datacore_constraint_name_invalid');
        }
        if (isset($this->constraints[$collection][$name])) {
            throw new RuntimeException('datacore_constraint_already_defined');
        }
        $this->constraints[$collection][$name] = $rule;
        return $this;
    }

    public function reference(
        string $collection,
        string $name,
        string $field,
        string $targetCollection,
    ): self {
        $this->typeFor($collection);
        $this->typeFor($targetCollection);
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $name)) {
            throw new RuntimeException('datacore_reference_name_invalid');
        }
        if (!preg_match('/^[a-z_][a-z0-9_]{0,127}$/i', $field)) {
            throw new RuntimeException('datacore_reference_field_invalid');
        }
        $this->references[$collection][$name] = ['field' => $field, 'target' => $targetCollection];
        return $this;
    }

    public function index(string $collection, string $name, array $fields): self
    {
        $this->typeFor($collection);
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $name)) {
            throw new RuntimeException('datacore_index_name_invalid');
        }
        if ($fields === []) throw new RuntimeException('datacore_index_fields_required');
        foreach ($fields as $field) {
            if (!is_string($field) || !preg_match('/^[a-z_][a-z0-9_]{0,127}$/i', $field)) {
                throw new RuntimeException('datacore_index_field_invalid');
            }
        }
        $this->secondaryIndexes->define($collection, $name, array_values(array_unique($fields)));
        return $this;
    }

    public function partialIndex(
        string $collection,
        string $name,
        array $fields,
        callable $predicate,
    ): self {
        $this->index($collection, $name, $fields);
        $this->secondaryIndexes->define($collection, $name, $fields, $predicate);
        return $this;
    }

    public function rangeIndex(string $collection, string $name, string $field): self
    {
        $this->typeFor($collection);
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $name)) {
            throw new RuntimeException('datacore_index_name_invalid');
        }
        if (!preg_match('/^[a-z_][a-z0-9_]{0,127}$/i', $field)) {
            throw new RuntimeException('datacore_index_field_invalid');
        }
        if (in_array($field, $this->encryptedFields[$collection] ?? [], true)) {
            throw new RuntimeException('datacore_range_index_encrypted_field_forbidden');
        }
        $this->secondaryIndexes->defineRange($collection, $name, $field);
        return $this;
    }

    public function findByIndex(string $collection, string $name, array $values, int $limit = 100): array
    {
        $this->typeFor($collection);
        if ($limit < 1 || $limit > 10_000) throw new RuntimeException('datacore_query_window_invalid');
        $ids = $this->secondaryIndexes->exact(
            $collection,
            $name,
            $values,
            fn(string $transactionId): bool => $this->transactionIsCommitted($transactionId),
        );
        $this->lastQueryPlan = [
            'strategy' => 'secondary_exact',
            'collection' => $collection,
            'index' => $name,
            'candidates' => count($ids),
            'limit' => $limit,
        ];
        $documents = [];
        foreach (array_slice($ids, 0, $limit) as $id) {
            $document = $this->find($collection, $id);
            if ($document !== null) $documents[] = $document;
        }
        return $documents;
    }

    public function findByRange(
        string $collection,
        string $name,
        int|float|null $minimum,
        int|float|null $maximum,
        int $limit = 100,
    ): array {
        $this->typeFor($collection);
        if ($limit < 1 || $limit > 10_000) throw new RuntimeException('datacore_query_window_invalid');
        $ids = $this->secondaryIndexes->range(
            $collection,
            $name,
            $minimum,
            $maximum,
            fn(string $transactionId): bool => $this->transactionIsCommitted($transactionId),
        );
        $this->lastQueryPlan = [
            'strategy' => 'secondary_range',
            'collection' => $collection,
            'index' => $name,
            'candidates' => count($ids),
            'limit' => $limit,
        ];
        $documents = [];
        foreach (array_slice($ids, 0, $limit) as $id) {
            $document = $this->find($collection, $id);
            if ($document !== null) $documents[] = $document;
        }
        return $documents;
    }

    public function transactionVisibility(callable $committed): self
    {
        $this->transactionCommitted = $committed;
        foreach ($this->sqlMirrors as $mirror) {
            $mirror['outbox']->transactionVisibility($committed);
        }
        return $this;
    }

    public function sqlMirror(string $collection, SqlMirrorOutbox $outbox, array $fields): self
    {
        $this->typeFor($collection);
        if ($fields === []) throw new RuntimeException('sql_mirror_fields_required');
        $subjectFields = $this->subjectEncryption[$collection]['fields'] ?? [];
        foreach ($fields as $field) {
            if (!is_string($field) || !preg_match('/^[a-z_][a-z0-9_]{0,127}$/i', $field)) {
                throw new RuntimeException('sql_mirror_field_invalid');
            }
            if (in_array($field, $this->encryptedFields[$collection] ?? [], true)
                || in_array($field, $subjectFields, true)) {
                throw new RuntimeException('sql_mirror_encrypted_field_forbidden');
            }
        }
        if ($this->transactionCommitted !== null) {
            $outbox->transactionVisibility($this->transactionCommitted);
        }
        $this->sqlMirrors[$collection] = [
            'outbox' => $outbox,
            'fields' => array_values(array_unique($fields)),
        ];
        return $this;
    }

    public function compactionGuard(callable $allowed): self
    {
        $this->storage->compactionGuard($allowed);
        return $this;
    }

    /**
     * Prevalida un lote externo completo antes de permitir su primera escritura.
     * La escritura definitiva vuelve a ejecutar todas las validaciones bajo bloqueo.
     */
    public function validateInsertDocuments(string $collection, array $documents): void
    {
        $type = $this->typeFor($collection);
        $this->withConstraints($collection, function () use ($collection, $documents, $type): void {
            $ids = [];
            $batchUnique = [];
            foreach ($documents as $document) {
                if (!is_array($document)) throw new RuntimeException('datacore_document_array_required');
                $this->types->assert($type, $document, 'document');
                $id = (string) ($document['id'] ?? '');
                if ($id === '') throw new RuntimeException('datacore_id_required');
                if (isset($ids[$id])) throw new RuntimeException('datacore_batch_id_conflict:' . $id);
                $ids[$id] = true;
                if ($this->storage->find($collection, $id) !== null) {
                    throw new RuntimeException('datacore_document_exists');
                }
                $this->assertConstraints($collection, $document);
                $this->assertReferences($collection, $document);
                $this->assertUnique($collection, $document, $id);
                foreach ($this->uniqueIndexes[$collection] ?? [] as $name => $fields) {
                    $values = [];
                    foreach ($fields as $field) {
                        if (!array_key_exists($field, $document)) {
                            throw new RuntimeException('datacore_unique_field_missing:' . $name . ':' . $field);
                        }
                        $values[$field] = $document[$field];
                    }
                    $identity = hash('sha256', PhpSerializer::encode($values));
                    if (isset($batchUnique[$name][$identity])) {
                        throw new RuntimeException('datacore_unique_conflict:' . $name);
                    }
                    $batchUnique[$name][$identity] = true;
                }
            }
        });
    }

    public function insert(string $collection, array $document, ?string $transactionId = null): array
    {
        $type = $this->typeFor($collection);
        $this->types->assert($type, $document, 'document');
        $id = (string) ($document['id'] ?? '');
        if ($id === '') throw new RuntimeException('datacore_id_required');
        return $this->withConstraints($collection, function () use ($collection, $document, $transactionId, $id): array {
            return $this->locked($collection, $id, function () use ($collection, $document, $transactionId): array {
            if ($this->storage->find($collection, (string) $document['id']) !== null) throw new RuntimeException('datacore_document_exists');
            $this->assertConstraints($collection, $document);
            $this->assertReferences($collection, $document);
            $this->assertUnique($collection, $document, (string) $document['id']);
            $metadata = ['_version' => 1, '_created_at' => microtime(true), '_updated_at' => microtime(true), '_ts' => time()];
            if ($transactionId !== null) $metadata['_transaction_id'] = $transactionId;
            $stored = $this->protect($collection, $document + $metadata);
            $this->storage->insert($collection, $stored); $this->storage->flush();
            $this->secondaryIndexes->record($collection, (string) $document['id'], $document, $transactionId);
            $this->enqueueSqlMirror($collection, $document, 1, 'upsert', $transactionId);
            return $this->unprotect($collection, $stored);
            });
        });
    }

    public function find(string $collection, string $id): ?array
    {
        $this->typeFor($collection); $stored = $this->storage->find($collection, $id);
        if (!is_array($stored)) {
            $stored = $this->storage->findIncludingDeleted($collection, $id);
        }
        if (is_array($stored) && !$this->isVisible($stored)) {
            $stored = $this->storage->findLatestMatching(
                $collection,
                $id,
                fn(array $candidate): bool => $this->isVisible($candidate),
            );
        }
        if (!is_array($stored) || ($stored['_deleted'] ?? false) === true) {
            return null;
        }
        return $this->unprotect($collection, $stored);
    }

    public function findForTransaction(string $collection, string $id, string $transactionId): ?array
    {
        $this->typeFor($collection);
        $stored = $this->storage->find($collection, $id);
        if (!is_array($stored)) {
            $stored = $this->storage->findIncludingDeleted($collection, $id);
        }
        if (!is_array($stored)) return null;
        if (($stored['_transaction_id'] ?? null) !== $transactionId && !$this->isVisible($stored)) {
            return null;
        }
        return $this->unprotect($collection, $stored);
    }

    public function update(string $collection, string $id, array $document, int $expectedVersion, ?string $transactionId = null): array
    {
        $type = $this->typeFor($collection);
        $this->types->assert($type, $document, 'document');
        if (($document['id'] ?? null) !== $id) throw new RuntimeException('datacore_id_immutable');
        return $this->withConstraints($collection, function () use ($collection, $id, $document, $expectedVersion, $transactionId): array {
            return $this->locked($collection, $id, function () use ($collection, $id, $document, $expectedVersion, $transactionId): array {
            $currentStored = $this->storage->find($collection, $id);
            $current = is_array($currentStored) ? $this->unprotect($collection, $currentStored) : null;
            if ($current === null) throw new RuntimeException('datacore_document_not_found');
            if (($current['_transaction_id'] ?? null) === $transactionId && $transactionId !== null) return $current;
            if ((int) ($current['_version'] ?? 0) !== $expectedVersion) throw new RuntimeException('datacore_version_conflict');
            $this->assertConstraints($collection, $document);
            $this->assertReferences($collection, $document);
            $this->assertUnique($collection, $document, $id);
            $metadata = [
                '_version' => $expectedVersion + 1,
                '_created_at' => $current['_created_at'] ?? microtime(true),
                '_updated_at' => microtime(true),
                '_ts' => time(),
            ];
            if ($transactionId !== null) $metadata['_transaction_id'] = $transactionId;
            $stored = $this->protect($collection, $document + $metadata);
            $this->storage->insert($collection, $stored); $this->storage->flush();
            $this->secondaryIndexes->record($collection, $id, $document, $transactionId);
            $this->enqueueSqlMirror(
                $collection,
                $document,
                $expectedVersion + 1,
                'upsert',
                $transactionId,
            );
            return $this->unprotect($collection, $stored);
            });
        });
    }

    public function delete(
        string $collection,
        string $id,
        int $expectedVersion,
        ?string $transactionId = null,
    ): void
    {
        $this->typeFor($collection);
        $this->withConstraints($collection, function () use (
            $collection, $id, $expectedVersion, $transactionId,
        ): void {
            $this->locked($collection, $id, function () use (
                $collection,
                $id,
                $expectedVersion,
                $transactionId,
            ): void {
            $currentStored = $this->storage->find($collection, $id);
            $current = is_array($currentStored) ? $this->unprotect($collection, $currentStored) : null;
            if ($current === null) throw new RuntimeException('datacore_document_not_found');
            if ((int) ($current['_version'] ?? 0) !== $expectedVersion) throw new RuntimeException('datacore_version_conflict');
            $this->assertNotReferenced($collection, $id);
            if ($transactionId === null) {
                $this->storage->delete($collection, $id);
            } else {
                $tombstone = $this->protect($collection, [
                    'id' => $id,
                    '_deleted' => true,
                    '_version' => $expectedVersion + 1,
                    '_transaction_id' => $transactionId,
                    '_ts' => time(),
                ]);
                $this->storage->insert($collection, $tombstone);
            }
            $this->storage->flush();
            $this->secondaryIndexes->record($collection, $id, [], $transactionId, true);
            $this->enqueueSqlMirror(
                $collection,
                ['id' => $id],
                $expectedVersion + 1,
                'delete',
                $transactionId,
            );
            });
        });
    }

    public function query(
        string $collection,
        callable $predicate,
        int $limit = 100,
        int $offset = 0,
        bool $allowCollectionScan = false,
    ): array
    {
        $this->typeFor($collection);
        if ($limit < 1 || $limit > 10_000 || $offset < 0) throw new RuntimeException('datacore_query_window_invalid');
        if (!$allowCollectionScan) {
            throw new RuntimeException('datacore_collection_scan_requires_explicit_scan');
        }
        $results = [];
        $scanned = 0;
        foreach ($this->storage->allLatest($collection, true) as $stored) {
            $scanned++;
            if (!$this->isVisible($stored)) {
                $id = (string) ($stored['id'] ?? '');
                $stored = $id === '' ? null : $this->storage->findLatestMatching(
                    $collection,
                    $id,
                    fn(array $candidate): bool => $this->isVisible($candidate),
                );
            }
            if (!is_array($stored) || ($stored['_deleted'] ?? false) === true) continue;
            $document = $this->unprotect($collection, $stored);
            if ($predicate($document)) $results[] = $document;
        }
        $this->lastQueryPlan = [
            'strategy' => 'collection_scan',
            'collection' => $collection,
            'scanned' => $scanned,
            'limit' => $limit,
            'offset' => $offset,
        ];
        return array_slice($results, $offset, $limit);
    }

    public function scan(string $collection, callable $predicate, int $limit = 100, int $offset = 0): array
    {
        return $this->query($collection, $predicate, $limit, $offset, true);
    }

    public function reindex(string $collection): array
    {
        $this->typeFor($collection);
        return $this->withConstraints($collection, function () use ($collection): array {
            $documents = [];
            foreach ($this->storage->allLatest($collection, true) as $stored) {
                if (!$this->isVisible($stored) || ($stored['_deleted'] ?? false) === true) continue;
                $document = $this->unprotect($collection, $stored);
                $documents[(string) $document['id']] = $document;
            }
            return $this->secondaryIndexes->rebuild($collection, $documents);
        });
    }

    public function lastQueryPlan(): array
    {
        return $this->lastQueryPlan;
    }

    private function isVisible(array $stored): bool
    {
        $transactionId = $stored['_transaction_id'] ?? null;
        if (!is_string($transactionId) || $transactionId === '') {
            return true;
        }
        return $this->transactionIsCommitted($transactionId);
    }

    private function transactionIsCommitted(string $transactionId): bool
    {
        return $this->transactionCommitted !== null
            && ($this->transactionCommitted)($transactionId) === true;
    }

    private function typeFor(string $collection): string
    {
        return $this->collections[$collection] ?? throw new RuntimeException('datacore_collection_not_defined');
    }

    private function assertUnique(string $collection, array $document, string $documentId): void
    {
        foreach ($this->uniqueIndexes[$collection] ?? [] as $name => $fields) {
            $values = [];
            foreach ($fields as $field) {
                if (!array_key_exists($field, $document)) {
                    throw new RuntimeException('datacore_unique_field_missing:' . $name . ':' . $field);
                }
                $values[$field] = $document[$field];
            }
            $conflicts = $this->query(
                $collection,
                static function (array $existing) use ($values, $documentId): bool {
                    if (($existing['id'] ?? null) === $documentId) return false;
                    foreach ($values as $field => $value) {
                        if (($existing[$field] ?? null) !== $value) return false;
                    }
                    return true;
                },
                1,
                0,
                true,
            );
            if ($conflicts !== []) throw new RuntimeException('datacore_unique_conflict:' . $name);
        }
    }

    private function enqueueSqlMirror(
        string $collection,
        array $document,
        int $version,
        string $operation,
        ?string $transactionId,
    ): void {
        $mirror = $this->sqlMirrors[$collection] ?? null;
        if (!is_array($mirror)) return;
        $projection = [];
        foreach ($mirror['fields'] as $field) {
            if (array_key_exists($field, $document)) $projection[$field] = $document[$field];
        }
        $documentId = (string) ($document['id'] ?? '');
        $operationId = $transactionId !== null
            ? $transactionId . ':' . $collection . ':' . $documentId . ':' . $version
            : 'direct:' . bin2hex(random_bytes(16));
        $mirror['outbox']->enqueue(
            $operationId,
            $collection,
            $operation,
            $documentId,
            $version,
            $projection,
            $transactionId,
        );
    }

    private function assertConstraints(string $collection, array $document): void
    {
        foreach ($this->constraints[$collection] ?? [] as $name => $rule) {
            if ($rule($document) !== true) {
                throw new RuntimeException('datacore_constraint_failed:' . $name);
            }
        }
    }

    private function assertReferences(string $collection, array $document): void
    {
        foreach ($this->references[$collection] ?? [] as $name => $reference) {
            $targetId = $document[$reference['field']] ?? null;
            if (!is_string($targetId) || $targetId === '') {
                throw new RuntimeException('datacore_reference_value_invalid:' . $name);
            }
            if ($this->find($reference['target'], $targetId) === null) {
                throw new RuntimeException('datacore_reference_not_found:' . $name);
            }
        }
    }

    private function assertNotReferenced(string $targetCollection, string $targetId): void
    {
        foreach ($this->references as $sourceCollection => $references) {
            foreach ($references as $name => $reference) {
                if ($reference['target'] !== $targetCollection) continue;
                $dependents = $this->query(
                    $sourceCollection,
                    static fn(array $document): bool => ($document[$reference['field']] ?? null) === $targetId,
                    1,
                    0,
                    true,
                );
                if ($dependents !== []) {
                    throw new RuntimeException('datacore_reference_restrict:' . $name);
                }
            }
        }
    }

    private function withConstraints(string $collection, callable $operation): mixed
    {
        $path = $this->lockDirectory . '/' . hash('sha256', 'constraints:global') . '.lock';
        $handle = fopen($path, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('datacore_constraint_lock_failed');
        }
        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function locked(string $collection, string $id, callable $operation): mixed
    {
        $path = $this->lockDirectory . '/' . hash('sha256', $collection . "\0" . $id) . '.lock';
        $handle = fopen($path, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('datacore_document_lock_failed');
        try { return $operation(); } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function protect(string $collection, array $document): array
    {
        $subjectDefinition = $this->subjectEncryption[$collection] ?? null;
        if (is_array($subjectDefinition)) {
            $subjectId = $document[$subjectDefinition['subject_field']] ?? null;
            if (!is_string($subjectId) || $subjectId === '') {
                throw new RuntimeException('datacore_subject_id_required');
            }
            foreach ($subjectDefinition['fields'] as $field) {
                if (!array_key_exists($field, $document) || $document[$field] === null) continue;
                $envelope = $subjectDefinition['vault']->encrypt(
                    $subjectId,
                    'datacore-subject-field:' . $collection . ':' . $field,
                    PhpSerializer::encode($document[$field]),
                );
                $document[$field] = [
                    '_jas_subject_encrypted' => $envelope['ciphertext'],
                    '_jas_subject_key_id' => $envelope['subject_key_id'],
                ];
            }
        }
        foreach ($this->encryptedFields[$collection] ?? [] as $field) {
            if (!array_key_exists($field, $document) || $document[$field] === null) continue;
            $plain = PhpSerializer::encode($document[$field]);
            $envelope = $this->keyRing->encrypt('datacore-field:' . $collection . ':' . $field, $plain);
            $document[$field] = ['_jas_encrypted' => $envelope['ciphertext'], '_jas_key_id' => $envelope['key_id']];
        }
        unset($document['_integrity'], $document['_integrity_key']);
        $signed = $this->keyRing->sign('datacore-integrity', PhpSerializer::encode($document));
        $document['_integrity'] = $signed['signature']; $document['_integrity_key'] = $signed['key_id'];
        return $document;
    }

    private function unprotect(string $collection, array $document): array
    {
        $integrity = $document['_integrity'] ?? null; $integrityKey = $document['_integrity_key'] ?? null;
        unset($document['_integrity'], $document['_integrity_key']);
        if (!is_string($integrity) || !is_string($integrityKey) || !$this->keyRing->verify('datacore-integrity', PhpSerializer::encode($document), $integrityKey, $integrity)) {
            throw new RuntimeException('datacore_integrity_failed');
        }
        foreach ($this->encryptedFields[$collection] ?? [] as $field) {
            $envelope = $document[$field] ?? null;
            if (!is_array($envelope) || !is_string($envelope['_jas_encrypted'] ?? null)) continue;
            $keyId = $envelope['_jas_key_id'] ?? null;
            if (!is_string($keyId)) throw new RuntimeException('datacore_encrypted_field_corrupt');
            $plain = $this->keyRing->decrypt('datacore-field:' . $collection . ':' . $field, $keyId, $envelope['_jas_encrypted']);
            $document[$field] = PhpSerializer::decode($plain);
        }
        $subjectDefinition = $this->subjectEncryption[$collection] ?? null;
        if (is_array($subjectDefinition)) {
            $subjectId = $document[$subjectDefinition['subject_field']] ?? null;
            if (!is_string($subjectId) || $subjectId === '') {
                throw new RuntimeException('datacore_subject_id_required');
            }
            foreach ($subjectDefinition['fields'] as $field) {
                $envelope = $document[$field] ?? null;
                if (!is_array($envelope)
                    || !is_string($envelope['_jas_subject_encrypted'] ?? null)) continue;
                $plain = $subjectDefinition['vault']->decrypt(
                    $subjectId,
                    'datacore-subject-field:' . $collection . ':' . $field,
                    $envelope['_jas_subject_encrypted'],
                );
                $document[$field] = PhpSerializer::decode($plain);
            }
        }
        $document['_integrity'] = $integrity; $document['_integrity_key'] = $integrityKey;
        return $document;
    }
}
