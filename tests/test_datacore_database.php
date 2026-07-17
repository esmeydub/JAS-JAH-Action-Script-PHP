<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\DataCore\DataCoreDatabase;
use Jah\DataCore\DataCoreMigration;
use Jah\DataCore\DataCoreMigrator;
use Jah\DataCore\DataCoreTurbo;
use Jah\DataCore\PhpSerializer;
use Jah\JAS\Type\TypeRegistry;
use Jah\JAS\Security\KeyRing;
use Jah\JAS\Security\DataGovernancePolicy;
use Jah\JAS\Persistence\AuditJournal;
use Jah\DataCore\DataRetentionService;
use Jah\DataCore\DataCoreTransactionManager;
use Jah\DataCore\ReversibleDataCoreMigration;
use Jah\DataCore\CompatibleDataCoreMigration;
use Jah\DataCore\SubjectKeyVault;
use Jah\DataCore\PdoSqlMirror;
use Jah\DataCore\SqlMirrorOutbox;
use Jah\DataCore\SqlMirrorWorker;
use Jah\DataCore\SqlMirrorResilienceStore;
use Jah\DataCore\GovernedSqlImporter;
use Jah\DataCore\SqlMirrorMode;
use Jah\DataCore\SqlMirrorAuditJournal;
use Jah\JAS\Security\DualControlStore;

$throws = static function (callable $operation, string $expected): void {
    try { $operation(); } catch (Throwable $e) { if ($e->getMessage() === $expected) return; throw $e; }
    throw new RuntimeException("Expected {$expected}");
};
$base = sys_get_temp_dir() . '/jas_database_' . bin2hex(random_bytes(5));
$storage = new DataCoreTurbo($base . '/storage', 1);
$types = (new TypeRegistry())->define('Usuario', ['id' => 'identifier', 'nombre' => 'non-empty-string', 'rol' => 'identifier']);
$oldKey = random_bytes(32); $newKey = random_bytes(32);
$database = (new DataCoreDatabase($storage, $types, $base . '/runtime', new KeyRing(['key-2025' => $oldKey], 'key-2025')))
    ->collection('usuarios', 'Usuario')
    ->encryptFields('usuarios', ['nombre']);

$created = $database->insert('usuarios', ['id' => 'USER-1', 'nombre' => 'Ana', 'rol' => 'admin']);
if (($created['_version'] ?? null) !== 1) throw new RuntimeException('datacore_insert_version_failed');
$raw = $storage->find('usuarios', 'USER-1');
if (!is_array($raw['nombre'] ?? null) || isset($raw['nombre'][0]) || str_contains(serialize($raw['nombre']), 'Ana')) throw new RuntimeException('datacore_field_not_encrypted');
$rotatedDatabase = (new DataCoreDatabase($storage, $types, $base . '/runtime-rotated', new KeyRing(['key-2025' => $oldKey, 'key-2026' => $newKey], 'key-2026')))
    ->collection('usuarios', 'Usuario')->encryptFields('usuarios', ['nombre']);
if (($rotatedDatabase->find('usuarios', 'USER-1')['nombre'] ?? null) !== 'Ana') throw new RuntimeException('datacore_old_key_read_failed');
$database = $rotatedDatabase;
$throws(fn() => $database->insert('usuarios', ['id' => 'USER-1', 'nombre' => 'Duplicada', 'rol' => 'admin']), 'datacore_document_exists');
$updated = $database->update('usuarios', 'USER-1', ['id' => 'USER-1', 'nombre' => 'Ana María', 'rol' => 'admin'], 1);
if (($updated['_version'] ?? null) !== 2) throw new RuntimeException('datacore_update_version_failed');
$rotatedRaw = $storage->find('usuarios', 'USER-1');
if (($rotatedRaw['nombre']['_jas_key_id'] ?? null) !== 'key-2026' || ($rotatedRaw['_integrity_key'] ?? null) !== 'key-2026') throw new RuntimeException('datacore_key_rotation_failed');
$throws(fn() => $database->update('usuarios', 'USER-1', ['id' => 'USER-1', 'nombre' => 'Conflicto', 'rol' => 'admin'], 1), 'datacore_version_conflict');
$throws(
    fn() => $database->query('usuarios', static fn(array $doc): bool => true),
    'datacore_collection_scan_requires_explicit_scan',
);
if (count($database->scan('usuarios', static fn(array $doc): bool => ($doc['rol'] ?? '') === 'admin')) !== 1) throw new RuntimeException('datacore_query_failed');

$uniqueTypes = (new TypeRegistry())->define('IdentidadUnica', [
    'id' => 'identifier',
    'institucion' => 'identifier',
    'correo' => 'non-empty-string',
]);
$uniqueKey = random_bytes(32);
$uniqueStorage = new DataCoreTurbo($base . '/unique-storage', 1);
$uniqueDatabase = (new DataCoreDatabase(
    $uniqueStorage,
    $uniqueTypes,
    $base . '/unique-runtime',
    $uniqueKey,
))->collection('identidades', 'IdentidadUnica')
    ->uniqueIndex('identidades', 'institucion_correo', ['institucion', 'correo']);
$uniqueDatabase->insert('identidades', [
    'id' => 'IDENTIDAD-1',
    'institucion' => 'GOB-MX',
    'correo' => 'persona@gob.mx',
]);
$indexedIdentity = $uniqueDatabase->findByIndex(
    'identidades',
    'institucion_correo',
    ['institucion' => 'GOB-MX', 'correo' => 'persona@gob.mx'],
);
if (count($indexedIdentity) !== 1 || ($indexedIdentity[0]['id'] ?? null) !== 'IDENTIDAD-1') {
    throw new RuntimeException('datacore_composite_index_lookup_failed');
}
$throws(
    fn() => $uniqueDatabase->insert('identidades', [
        'id' => 'IDENTIDAD-2',
        'institucion' => 'GOB-MX',
        'correo' => 'persona@gob.mx',
    ]),
    'datacore_unique_conflict:institucion_correo',
);
$uniqueDatabase->insert('identidades', [
    'id' => 'IDENTIDAD-3',
    'institucion' => 'EMPRESA-MX',
    'correo' => 'persona@gob.mx',
]);
$uniqueDatabase->update('identidades', 'IDENTIDAD-3', [
    'id' => 'IDENTIDAD-3',
    'institucion' => 'EMPRESA-MX',
    'correo' => 'nuevo@empresa.mx',
], 1);
if ($uniqueDatabase->findByIndex(
    'identidades',
    'institucion_correo',
    ['institucion' => 'EMPRESA-MX', 'correo' => 'persona@gob.mx'],
) !== []) {
    throw new RuntimeException('datacore_index_stale_update_posting');
}
if (count($uniqueDatabase->findByIndex(
    'identidades',
    'institucion_correo',
    ['institucion' => 'EMPRESA-MX', 'correo' => 'nuevo@empresa.mx'],
)) !== 1) {
    throw new RuntimeException('datacore_index_updated_lookup_failed');
}

if (extension_loaded('pcntl')) {
    $uniqueChildren = [];
    foreach (['IDENTIDAD-4', 'IDENTIDAD-5'] as $identityId) {
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('datacore_unique_fork_failed');
        if ($pid === 0) {
            try {
                $uniqueDatabase->insert('identidades', [
                    'id' => $identityId,
                    'institucion' => 'MUNICIPIO-MX',
                    'correo' => 'concurrente@gob.mx',
                ]);
                exit(0);
            } catch (RuntimeException $error) {
                exit($error->getMessage() === 'datacore_unique_conflict:institucion_correo' ? 10 : 11);
            }
        }
        $uniqueChildren[] = $pid;
    }
    $uniqueOutcomes = [];
    foreach ($uniqueChildren as $pid) {
        pcntl_waitpid($pid, $status);
        $uniqueOutcomes[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 99;
    }
    sort($uniqueOutcomes);
    if ($uniqueOutcomes !== [0, 10]) {
        throw new RuntimeException('datacore_concurrent_unique_constraint_failed');
    }
}

$indexTypes = (new TypeRegistry())->define('MovimientoIndexado', [
    'id' => 'identifier',
    'cuenta' => 'identifier',
    'monto' => 'non-negative-int',
]);
$indexDatabase = (new DataCoreDatabase(
    new DataCoreTurbo($base . '/range-storage', 1),
    $indexTypes,
    $base . '/range-runtime',
    random_bytes(32),
))->collection('movimientos', 'MovimientoIndexado')
    ->rangeIndex('movimientos', 'monto_rango', 'monto')
    ->partialIndex(
        'movimientos',
        'cuenta_relevante',
        ['cuenta'],
        static fn(array $document): bool => ($document['monto'] ?? 0) >= 100,
    );
foreach ([25, 100, 250, 500] as $position => $amount) {
    $indexDatabase->insert('movimientos', [
        'id' => 'MOV-' . $position,
        'cuenta' => $position < 3 ? 'CUENTA-INDEX' : 'CUENTA-OTRA',
        'monto' => $amount,
    ]);
}
$rangeResults = $indexDatabase->findByRange('movimientos', 'monto_rango', 100, 300);
if (array_column($rangeResults, 'monto') !== [100, 250]) {
    throw new RuntimeException('datacore_range_index_failed');
}
if (($indexDatabase->lastQueryPlan()['strategy'] ?? null) !== 'secondary_range'
    || ($indexDatabase->lastQueryPlan()['index'] ?? null) !== 'monto_rango') {
    throw new RuntimeException('datacore_range_query_plan_missing');
}
$partialResults = $indexDatabase->findByIndex(
    'movimientos',
    'cuenta_relevante',
    ['cuenta' => 'CUENTA-INDEX'],
);
if (array_column($partialResults, 'monto') !== [100, 250]) {
    throw new RuntimeException('datacore_partial_index_failed');
}
$throws(
    fn() => $database->rangeIndex('usuarios', 'nombre_rango', 'nombre'),
    'datacore_range_index_encrypted_field_forbidden',
);

$lateIndexDatabase = (new DataCoreDatabase(
    new DataCoreTurbo($base . '/late-index-storage', 1),
    $indexTypes,
    $base . '/late-index-runtime',
    random_bytes(32),
))->collection('movimientos', 'MovimientoIndexado');
$lateIndexDatabase->insert('movimientos', [
    'id' => 'MOV-LATE',
    'cuenta' => 'CUENTA-LATE',
    'monto' => 900,
]);
$lateIndexDatabase->index('movimientos', 'cuenta_exacta', ['cuenta']);
if ($lateIndexDatabase->findByIndex(
    'movimientos',
    'cuenta_exacta',
    ['cuenta' => 'CUENTA-LATE'],
) !== []) {
    throw new RuntimeException('datacore_index_unexpected_implicit_backfill');
}
$reindexReport = $lateIndexDatabase->reindex('movimientos');
if (($reindexReport['documents'] ?? null) !== 1
    || ($reindexReport['indexes'] ?? null) !== 1
    || count($lateIndexDatabase->findByIndex(
        'movimientos',
        'cuenta_exacta',
        ['cuenta' => 'CUENTA-LATE'],
    )) !== 1
    || ($lateIndexDatabase->lastQueryPlan()['strategy'] ?? null) !== 'secondary_exact') {
    throw new RuntimeException('datacore_online_reindex_failed');
}

$relationTypes = (new TypeRegistry())
    ->define('PersonaRelacion', ['id' => 'identifier', 'nombre' => 'non-empty-string'])
    ->define('CuentaRelacion', [
        'id' => 'identifier',
        'titular_id' => 'identifier',
        'saldo' => 'non-negative-int',
    ]);
$relationDatabase = (new DataCoreDatabase(
    new DataCoreTurbo($base . '/relation-storage', 1),
    $relationTypes,
    $base . '/relation-runtime',
    random_bytes(32),
))->collection('personas', 'PersonaRelacion')
    ->collection('cuentas_relacion', 'CuentaRelacion')
    ->constraint(
        'cuentas_relacion',
        'saldo_institucional_maximo',
        static fn(array $document): bool => ($document['saldo'] ?? PHP_INT_MAX) <= 1_000_000,
    )
    ->reference('cuentas_relacion', 'titular_existente', 'titular_id', 'personas');
$relationDatabase->insert('personas', ['id' => 'PERSONA-1', 'nombre' => 'Ana']);
$throws(
    fn() => $relationDatabase->insert('cuentas_relacion', [
        'id' => 'CUENTA-SIN-TITULAR',
        'titular_id' => 'PERSONA-INEXISTENTE',
        'saldo' => 10,
    ]),
    'datacore_reference_not_found:titular_existente',
);
$throws(
    fn() => $relationDatabase->insert('cuentas_relacion', [
        'id' => 'CUENTA-EXCESIVA',
        'titular_id' => 'PERSONA-1',
        'saldo' => 1_000_001,
    ]),
    'datacore_constraint_failed:saldo_institucional_maximo',
);
$relationDatabase->insert('cuentas_relacion', [
    'id' => 'CUENTA-VALIDA',
    'titular_id' => 'PERSONA-1',
    'saldo' => 500,
]);
$throws(
    fn() => $relationDatabase->delete('personas', 'PERSONA-1', 1),
    'datacore_reference_restrict:titular_existente',
);
$relationDatabase->delete('cuentas_relacion', 'CUENTA-VALIDA', 1);
$relationDatabase->delete('personas', 'PERSONA-1', 1);

$subjectTypes = (new TypeRegistry())->define('ExpedienteSujeto', [
    'id' => 'identifier',
    'sujeto_id' => 'identifier',
    'secreto' => 'non-empty-string',
]);
$subjectMaster = new KeyRing(['subject-master' => random_bytes(32)], 'subject-master');
$subjectVault = new SubjectKeyVault($base . '/subject-vault', $subjectMaster);
$subjectStorage = new DataCoreTurbo($base . '/subject-storage', 1);
$subjectDatabase = (new DataCoreDatabase(
    $subjectStorage,
    $subjectTypes,
    $base . '/subject-runtime',
    $subjectMaster,
))->collection('expedientes_sujeto', 'ExpedienteSujeto')
    ->encryptFieldsBySubject('expedientes_sujeto', 'sujeto_id', ['secreto'], $subjectVault);
$subjectDatabase->insert('expedientes_sujeto', [
    'id' => 'EXP-SUJETO-A',
    'sujeto_id' => 'SUJETO-A',
    'secreto' => 'dato reservado A',
]);
$subjectDatabase->insert('expedientes_sujeto', [
    'id' => 'EXP-SUJETO-B',
    'sujeto_id' => 'SUJETO-B',
    'secreto' => 'dato reservado B',
]);
$rawSubject = $subjectStorage->find('expedientes_sujeto', 'EXP-SUJETO-A');
if (!is_array($rawSubject['secreto'] ?? null)
    || str_contains(serialize($rawSubject), 'dato reservado A')) {
    throw new RuntimeException('datacore_subject_field_not_encrypted');
}
$subjectVault->destroy('SUJETO-A', 'privacy-officer');
if (!$subjectVault->isDestroyed('SUJETO-A')) {
    throw new RuntimeException('datacore_subject_key_destroy_not_recorded');
}
if (!$subjectVault->verifyDestructionLog()) {
    throw new RuntimeException('datacore_subject_destroy_log_invalid');
}
$throws(
    fn() => $subjectDatabase->find('expedientes_sujeto', 'EXP-SUJETO-A'),
    'datacore_subject_key_destroyed',
);
if (($subjectDatabase->find('expedientes_sujeto', 'EXP-SUJETO-B')['secreto'] ?? null)
    !== 'dato reservado B') {
    throw new RuntimeException('datacore_subject_key_destroy_affected_other_subject');
}

$mirrorPdo = new PDO('sqlite:' . $base . '/mirror.sqlite');
$mirrorPdo->exec(
    'CREATE TABLE expedientes_mirror ('
    . '_datacore_id TEXT PRIMARY KEY, '
    . '_datacore_version INTEGER NOT NULL, '
    . '_datacore_hash TEXT NOT NULL, '
    . 'sujeto TEXT, estado TEXT)',
);
$mirrorKeys = new KeyRing(['mirror-key' => random_bytes(32)], 'mirror-key');
$mirrorOutbox = new SqlMirrorOutbox($base . '/mirror-outbox', $mirrorKeys);
$mirrorAudit = new SqlMirrorAuditJournal($base . '/mirror-audit', $mirrorKeys);
$mirror = (new PdoSqlMirror($mirrorPdo, $mirrorAudit))->map('expedientes', 'expedientes_mirror', [
    'sujeto_id' => 'sujeto',
    'estado' => 'estado',
]);
$mirrorOutbox->enqueue(
    'mirror-operation-1',
    'expedientes',
    'upsert',
    'EXP-MIRROR-1',
    2,
    ['sujeto_id' => 'SUJETO-B', 'estado' => "activo'); DROP TABLE expedientes_mirror; --"],
);
$mirrorWorker = new SqlMirrorWorker($mirrorOutbox, $mirror);
if ($mirrorWorker->synchronize()['applied'] !== 1) {
    throw new RuntimeException('sql_mirror_sync_failed');
}
$mirrored = $mirrorPdo->query(
    "SELECT estado, _datacore_version FROM expedientes_mirror WHERE _datacore_id='EXP-MIRROR-1'",
)->fetch(PDO::FETCH_ASSOC);
if (($mirrored['_datacore_version'] ?? null) !== 2
    || !str_contains((string) ($mirrored['estado'] ?? ''), 'DROP TABLE')) {
    throw new RuntimeException('sql_mirror_prepared_statement_failed');
}
$mirrorExpectedEntry = [
    'collection' => 'expedientes',
    'document_id' => 'EXP-MIRROR-1',
    'version' => 2,
    'projection' => [
        'sujeto_id' => 'SUJETO-B',
        'estado' => "activo'); DROP TABLE expedientes_mirror; --",
    ],
];
if (($mirror->reconcile($mirrorExpectedEntry)['status'] ?? null) !== 'in_sync') {
    throw new RuntimeException('sql_mirror_reconciliation_false_divergence');
}
$mirrorOutbox->enqueue(
    'mirror-operation-old',
    'expedientes',
    'upsert',
    'EXP-MIRROR-1',
    1,
    ['sujeto_id' => 'ATACANTE', 'estado' => 'comprometido'],
);
$mirrorWorker->synchronize();
$versionAfterReplay = $mirrorPdo->query(
    "SELECT _datacore_version FROM expedientes_mirror WHERE _datacore_id='EXP-MIRROR-1'",
)->fetchColumn();
if ((int) $versionAfterReplay !== 2) throw new RuntimeException('sql_mirror_version_rollback_allowed');
$mirrorPdo->exec("UPDATE expedientes_mirror SET _datacore_version=99");
if (($mirror->reconcile($mirrorExpectedEntry)['status'] ?? null) !== 'sql_ahead_untrusted') {
    throw new RuntimeException('sql_mirror_untrusted_ahead_not_detected');
}
$mirrorPdo->exec("UPDATE expedientes_mirror SET _datacore_version=2");
$mirrorPdo->exec("UPDATE expedientes_mirror SET estado='alterado-en-sql'");
if (($mirror->reconcile($mirrorExpectedEntry)['status'] ?? null) !== 'diverged') {
    throw new RuntimeException('sql_mirror_divergence_not_detected');
}
if (!$mirrorAudit->verify()) throw new RuntimeException('sql_mirror_reconciliation_audit_failed');
$mirrorAuditPath = $base . '/mirror-audit/reconciliation-audit.jahl';
$mirrorAuditLines = file($mirrorAuditPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$mirrorAuditEntry = PhpSerializer::decode((string) ($mirrorAuditLines[0] ?? ''));
$mirrorAuditEntry['status'] = 'missing';
$mirrorAuditLines[0] = PhpSerializer::encode($mirrorAuditEntry);
file_put_contents($mirrorAuditPath, implode("\n", $mirrorAuditLines) . "\n", LOCK_EX);
if ($mirrorAudit->verify()) throw new RuntimeException('sql_mirror_tampered_audit_accepted');
if (($subjectDatabase->find('expedientes_sujeto', 'EXP-SUJETO-B')['secreto'] ?? null)
    !== 'dato reservado B') {
    throw new RuntimeException('sql_mirror_contaminated_datacore');
}
$tamperedOutbox = new SqlMirrorOutbox($base . '/mirror-tampered', $mirrorKeys);
$tamperedOutbox->enqueue(
    'mirror-tampered-operation',
    'expedientes',
    'upsert',
    'EXP-TAMPERED',
    1,
    ['estado' => 'original'],
);
$tamperedPath = $base . '/mirror-tampered/sql-mirror.jahl';
$tamperedEntry = PhpSerializer::decode(trim((string) file_get_contents($tamperedPath)));
$tamperedEntry['signature'] = str_repeat('0', strlen((string) $tamperedEntry['signature']));
file_put_contents($tamperedPath, PhpSerializer::encode($tamperedEntry) . "\n", LOCK_EX);
$throws(fn() => $tamperedOutbox->pending(), 'sql_mirror_outbox_signature_invalid');

$mirrorPdo->exec(
    'CREATE TABLE documentos_auto ('
    . '_datacore_id TEXT PRIMARY KEY, '
    . '_datacore_version INTEGER NOT NULL, '
    . '_datacore_hash TEXT NOT NULL, '
    . 'estado TEXT)',
);
$autoMirrorOutbox = new SqlMirrorOutbox($base . '/mirror-auto-outbox', $mirrorKeys);
$autoMirror = (new PdoSqlMirror($mirrorPdo))->map(
    'documentos_auto',
    'documentos_auto',
    ['estado' => 'estado'],
);
$autoTypes = (new TypeRegistry())->define('DocumentoAuto', [
    'id' => 'identifier',
    'estado' => 'non-empty-string',
]);
$autoStorage = new DataCoreTurbo($base . '/mirror-auto-storage', 1);
$autoDatabase = (new DataCoreDatabase(
    $autoStorage,
    $autoTypes,
    $base . '/mirror-auto-runtime',
    random_bytes(32),
))->collection('documentos_auto', 'DocumentoAuto')
    ->sqlMirror('documentos_auto', $autoMirrorOutbox, ['estado']);
$autoTransactions = new DataCoreTransactionManager(
    $autoDatabase,
    $base . '/mirror-auto-transactions',
);
$autoWorker = new SqlMirrorWorker($autoMirrorOutbox, $autoMirror);
$autoDatabase->insert('documentos_auto', ['id' => 'AUTO-1', 'estado' => 'directo']);
if ($autoWorker->synchronize()['applied'] !== 1) {
    throw new RuntimeException('sql_mirror_automatic_direct_failed');
}
$autoTransaction = $autoTransactions->begin('mirror-transaction')
    ->insert('documentos_auto', ['id' => 'AUTO-2', 'estado' => 'transaccional']);
$autoTransactions->failureProbe(
    static fn(string $point) => $point === 'before_committed'
        ? throw new RuntimeException('simulated_mirror_transaction_crash')
        : null,
);
$throws(
    fn() => $autoTransactions->commit($autoTransaction),
    'simulated_mirror_transaction_crash',
);
$autoTransactions->failureProbe(null);
if ($autoMirrorOutbox->pending() !== [] || $autoWorker->synchronize()['applied'] !== 0) {
    throw new RuntimeException('sql_mirror_prepared_transaction_leaked');
}
$autoTransactions->recover();
if ($autoWorker->synchronize()['applied'] !== 1) {
    throw new RuntimeException('sql_mirror_committed_transaction_not_published');
}
$autoRows = $mirrorPdo->query('SELECT COUNT(*) FROM documentos_auto')->fetchColumn();
if ((int) $autoRows !== 2) throw new RuntimeException('sql_mirror_automatic_row_count_failed');
$throws(
    fn() => $subjectDatabase->sqlMirror(
        'expedientes_sujeto',
        $autoMirrorOutbox,
        ['secreto'],
    ),
    'sql_mirror_encrypted_field_forbidden',
);

$failureOutbox = new SqlMirrorOutbox($base . '/mirror-failure-outbox', $mirrorKeys);
$failureOutbox->enqueue(
    'mirror-failure-operation',
    'espejo_inestable',
    'upsert',
    'FAILURE-1',
    1,
    ['estado' => 'pendiente'],
);
$failureMirror = (new PdoSqlMirror($mirrorPdo))->map(
    'espejo_inestable',
    'tabla_sql_inexistente',
    ['estado' => 'estado'],
);
$resilience = new SqlMirrorResilienceStore(
    $base . '/mirror-resilience',
    failureThreshold: 2,
    quarantineAttempts: 3,
    cooldownSeconds: 1,
);
$failureWorker = new SqlMirrorWorker($failureOutbox, $failureMirror, $resilience);
$firstFailure = $failureWorker->synchronize();
$secondFailure = $failureWorker->synchronize();
if (($firstFailure['failed'] ?? null) !== 1
    || ($secondFailure['failed'] ?? null) !== 1
    || ($secondFailure['circuit_open'] ?? null) !== true
    || ($failureWorker->synchronize()['failed'] ?? null) !== 0) {
    throw new RuntimeException('sql_mirror_circuit_breaker_failed');
}
$autoDatabase->insert('documentos_auto', ['id' => 'AUTO-DURING-SQL-FAILURE', 'estado' => 'seguro']);
if ($autoDatabase->find('documentos_auto', 'AUTO-DURING-SQL-FAILURE') === null) {
    throw new RuntimeException('sql_mirror_failure_blocked_datacore');
}
usleep(1_100_000);
$thirdFailure = $failureWorker->synchronize();
if (($thirdFailure['failed'] ?? null) !== 1
    || ($thirdFailure['quarantined'] ?? null) !== 1
    || !$resilience->isQuarantined('mirror-failure-operation')) {
    throw new RuntimeException('sql_mirror_quarantine_failed');
}

$mirrorPdo->exec(
    'CREATE TABLE legado_personas ('
    . 'legacy_id TEXT PRIMARY KEY, correo TEXT NOT NULL, nombre TEXT NOT NULL)',
);
$legacyInsert = $mirrorPdo->prepare(
    'INSERT INTO legado_personas (legacy_id, correo, nombre) VALUES (?, ?, ?)',
);
$legacyPayload = "Robert'); DROP TABLE legado_personas; --";
$legacyInsert->execute(['LEGACY-1', 'persona@gob.mx', $legacyPayload]);
$importTypes = (new TypeRegistry())->define('PersonaImportada', [
    'id' => 'identifier',
    'correo' => 'non-empty-string',
    'nombre' => 'non-empty-string',
]);
$importDatabase = (new DataCoreDatabase(
    new DataCoreTurbo($base . '/sql-import-storage', 1),
    $importTypes,
    $base . '/sql-import-runtime',
    random_bytes(32),
))->collection('personas_importadas', 'PersonaImportada')
    ->uniqueIndex('personas_importadas', 'correo_unico', ['correo']);
$importApprovals = new DualControlStore($base . '/sql-import-approvals');
$disabledImporter = (new GovernedSqlImporter(
    $mirrorPdo,
    $importDatabase,
    $importApprovals,
))->map(
    'personas_importadas',
    'legado_personas',
    ['legacy_id' => 'id', 'correo' => 'correo', 'nombre' => 'nombre'],
    'legacy_id',
);
$throws(
    fn() => $disabledImporter->import('unused', 'IMPORT-0', 'personas_importadas', null, 100),
    'sql_import_disabled',
);
$importer = (new GovernedSqlImporter(
    $mirrorPdo,
    $importDatabase,
    $importApprovals,
    SqlMirrorMode::GovernedSqlMigration,
))->map(
    'personas_importadas',
    'legado_personas',
    ['legacy_id' => 'id', 'correo' => 'correo', 'nombre' => 'nombre'],
    'legacy_id',
);
$fingerprint = $importer->approvalFingerprint('personas_importadas', null, 100);
$approvalId = $importApprovals->request(
    GovernedSqlImporter::APPROVAL_ACTION,
    'operador-uno',
    'IMPORT-1',
    $fingerprint,
);
$throws(
    fn() => $importer->import($approvalId, 'IMPORT-1', 'personas_importadas', null, 100),
    'dual_control_not_approved',
);
$importApprovals->approve($approvalId, 'supervisor-dos');
$importResult = $importer->import(
    $approvalId,
    'IMPORT-1',
    'personas_importadas',
    null,
    100,
);
if (($importResult['imported'] ?? null) !== 1
    || ($importDatabase->find('personas_importadas', 'LEGACY-1')['nombre'] ?? null) !== $legacyPayload) {
    throw new RuntimeException('sql_governed_import_failed');
}
if (!$mirrorPdo->query(
    "SELECT name FROM sqlite_master WHERE type='table' AND name='legado_personas'",
)->fetchColumn()) {
    throw new RuntimeException('sql_import_value_executed');
}
$throws(
    fn() => $importer->import($approvalId, 'IMPORT-1', 'personas_importadas', null, 100),
    'dual_control_not_approved',
);

$mirrorPdo->exec(
    'CREATE TABLE legado_invalido ('
    . 'legacy_id TEXT PRIMARY KEY, correo TEXT NOT NULL, nombre BLOB)',
);
$invalidInsert = $mirrorPdo->prepare(
    'INSERT INTO legado_invalido (legacy_id, correo, nombre) VALUES (?, ?, ?)',
);
$invalidInsert->execute(['INVALID-1', 'valida@gob.mx', 'Nombre válido']);
$invalidInsert->execute(['INVALID-2', 'invalida@gob.mx', null]);
$invalidImporter = (new GovernedSqlImporter(
    $mirrorPdo,
    $importDatabase,
    $importApprovals,
    SqlMirrorMode::GovernedSqlMigration,
))->map(
    'personas_importadas',
    'legado_invalido',
    ['legacy_id' => 'id', 'correo' => 'correo', 'nombre' => 'nombre'],
    'legacy_id',
);
$invalidFingerprint = $invalidImporter->approvalFingerprint('personas_importadas', null, 100);
$invalidApproval = $importApprovals->request(
    GovernedSqlImporter::APPROVAL_ACTION,
    'operador-uno',
    'IMPORT-INVALID',
    $invalidFingerprint,
);
$importApprovals->approve($invalidApproval, 'supervisor-dos');
$throws(
    fn() => $invalidImporter->import(
        $invalidApproval,
        'IMPORT-INVALID',
        'personas_importadas',
        null,
        100,
    ),
    'document_type_mismatch:PersonaImportada',
);
if ($importDatabase->find('personas_importadas', 'INVALID-1') !== null) {
    throw new RuntimeException('sql_import_partial_contamination');
}

$migration = new class implements DataCoreMigration {
    public function version(): int { return 1; }
    public function name(): string { return 'crear_configuracion_inicial'; }
    public function checksum(): string { return hash('sha256', 'migration-1-v1'); }
    public function up(DataCoreTurbo $storage): void { $storage->insert('configuracion', ['id' => 'app', 'estado' => 'active']); }
};
$migrator = new DataCoreMigrator($storage);
if ($migrator->migrate([$migration]) !== 1 || $migrator->migrate([$migration]) !== 0) throw new RuntimeException('datacore_migration_idempotency_failed');
$reversibleMigration = new class implements ReversibleDataCoreMigration, CompatibleDataCoreMigration {
    public function version(): int { return 2; }
    public function name(): string { return 'activar_modulo_reversible'; }
    public function checksum(): string { return hash('sha256', 'migration-2-v1'); }
    public function backwardCompatible(): bool { return true; }
    public function up(DataCoreTurbo $storage): void
    {
        $storage->insert('configuracion', ['id' => 'modulo-reversible', 'estado' => 'active']);
    }
    public function down(DataCoreTurbo $storage): void
    {
        $storage->delete('configuracion', 'modulo-reversible');
    }
};
if ($migrator->migrate([$migration, $reversibleMigration]) !== 1) {
    throw new RuntimeException('datacore_reversible_migration_apply_failed');
}
if ($migrator->rollback([$migration, $reversibleMigration], 1) !== 1
    || $storage->find('configuracion', 'modulo-reversible') !== null) {
    throw new RuntimeException('datacore_migration_rollback_failed');
}
if ($migrator->migrate([$migration, $reversibleMigration]) !== 1) {
    throw new RuntimeException('datacore_migration_reapply_failed');
}
$breakingMigration = new class implements CompatibleDataCoreMigration {
    public function version(): int { return 3; }
    public function name(): string { return 'ruptura_controlada'; }
    public function checksum(): string { return hash('sha256', 'migration-3-v1'); }
    public function backwardCompatible(): bool { return false; }
    public function up(DataCoreTurbo $storage): void {}
};
$throws(
    fn() => $migrator->migrate([$breakingMigration]),
    'datacore_breaking_migration_requires_approval',
);
$failingMigration = new class implements ReversibleDataCoreMigration {
    public function version(): int { return 4; }
    public function name(): string { return 'fallo_con_rollback'; }
    public function checksum(): string { return hash('sha256', 'migration-4-v1'); }
    public function up(DataCoreTurbo $storage): void
    {
        $storage->insert('configuracion', ['id' => 'estado-parcial', 'estado' => 'unsafe']);
        $storage->flush();
        throw new RuntimeException('simulated_migration_failure');
    }
    public function down(DataCoreTurbo $storage): void
    {
        if ($storage->find('configuracion', 'estado-parcial') !== null) {
            $storage->delete('configuracion', 'estado-parcial');
        }
    }
};
$throws(
    fn() => $migrator->migrate([$failingMigration]),
    'simulated_migration_failure',
);
if ($storage->find('configuracion', 'estado-parcial') !== null
    || ($storage->find('_jas_migrations', 'migration-4')['state'] ?? null) !== 'ROLLED_BACK') {
    throw new RuntimeException('datacore_failed_migration_not_rolled_back');
}
$database->delete('usuarios', 'USER-1', 2);
if ($database->find('usuarios', 'USER-1') !== null) throw new RuntimeException('datacore_delete_failed');

$retentionTypes = (new TypeRegistry())->define('Expediente', ['id' => 'identifier', 'asunto' => 'non-empty-string', '_legal_hold?' => 'bool']);
$retentionStorage = new DataCoreTurbo($base . '/retention-storage', 1);
$retentionDatabase = (new DataCoreDatabase($retentionStorage, $retentionTypes, $base . '/retention-runtime', random_bytes(32)))
    ->collection('expedientes', 'Expediente');
$retentionDatabase->insert('expedientes', ['id' => 'EXP-OLD', 'asunto' => 'Vencido']);
$retentionDatabase->insert('expedientes', ['id' => 'EXP-HOLD', 'asunto' => 'Conservación', '_legal_hold' => true]);
$policy = (new DataGovernancePolicy())->collection('expedientes', 'Gestión legal de expedientes ciudadanos', 365);
$retentionAudit = new AuditJournal($base . '/retention-audit');
$retention = new DataRetentionService($retentionDatabase, $policy, $retentionAudit);
$future = time() + 400 * 86400;
$preview = $retention->enforce('expedientes', 'privacy-officer', true, $future);
if ($preview['expired'] !== 2 || $preview['held'] !== 1 || $preview['deleted'] !== 0) throw new RuntimeException('retention_preview_failed');
$applied = $retention->enforce('expedientes', 'privacy-officer', false, $future + 1);
if ($applied['deleted'] !== 1 || $retentionDatabase->find('expedientes', 'EXP-OLD') !== null || $retentionDatabase->find('expedientes', 'EXP-HOLD') === null) throw new RuntimeException('retention_enforcement_failed');
if (!$retentionAudit->verify()) throw new RuntimeException('retention_audit_failed');

$txTypes = (new TypeRegistry())->define('Cuenta', ['id' => 'identifier', 'saldo' => 'non-negative-int']);
$txStorage = new DataCoreTurbo($base . '/tx-storage', 1);
$txKey = random_bytes(32);
$txDatabase = (new DataCoreDatabase($txStorage, $txTypes, $base . '/tx-runtime', $txKey))->collection('cuentas', 'Cuenta');
$transactions = new DataCoreTransactionManager($txDatabase, $base . '/tx-journal');
$transaction = $transactions->begin('transfer-1')
    ->insert('cuentas', ['id' => 'CUENTA-A', 'saldo' => 100])
    ->insert('cuentas', ['id' => 'CUENTA-B', 'saldo' => 50]);
$transactions->commit($transaction);
if ($txDatabase->find('cuentas', 'CUENTA-A') === null || $txDatabase->find('cuentas', 'CUENTA-B') === null) throw new RuntimeException('datacore_transaction_commit_failed');
if ($transactions->recover() !== 0) throw new RuntimeException('datacore_committed_transaction_recovered');

$recovery = $transactions->begin('transfer-recovery')
    ->insert('cuentas', ['id' => 'CUENTA-C', 'saldo' => 25])
    ->insert('cuentas', ['id' => 'CUENTA-D', 'saldo' => 75]);
$journalReflection = new ReflectionClass($transactions);
$journalProperty = $journalReflection->getProperty('journal');
$journalPath = $journalProperty->getValue($transactions);
file_put_contents($journalPath, PhpSerializer::encode(['type' => 'PREPARED', 'id' => $recovery->id, 'operations' => $recovery->operations(), 'at' => microtime(true)]) . "\n", FILE_APPEND | LOCK_EX);
$txDatabase->insert('cuentas', ['id' => 'CUENTA-C', 'saldo' => 25], $recovery->id);
if ($txDatabase->find('cuentas', 'CUENTA-C') !== null) throw new RuntimeException('datacore_prepared_insert_became_visible');
$throws(
    fn() => $txStorage->compactCollection('cuentas', true),
    'datacore_compaction_transactions_pending',
);
$visibleDuringPrepare = $txDatabase->scan('cuentas', static fn(array $doc): bool => true, 100);
if (count($visibleDuringPrepare) !== 2) throw new RuntimeException('datacore_prepared_query_became_visible');
if ($transactions->recover() !== 1 || $txDatabase->find('cuentas', 'CUENTA-C') === null || $txDatabase->find('cuentas', 'CUENTA-D') === null) throw new RuntimeException('datacore_transaction_recovery_failed');
if (($txStorage->compactCollection('cuentas', true)['records_before'] ?? 0) < 4) {
    throw new RuntimeException('datacore_compaction_not_reenabled');
}

$updateRecovery = $transactions->begin('update-recovery')
    ->update('cuentas', 'CUENTA-A', ['id' => 'CUENTA-A', 'saldo' => 80], 1);
file_put_contents(
    $journalPath,
    PhpSerializer::encode([
        'type' => 'PREPARED',
        'id' => $updateRecovery->id,
        'operations' => $updateRecovery->operations(),
        'at' => microtime(true),
    ]) . "\n",
    FILE_APPEND | LOCK_EX,
);
$txDatabase->update('cuentas', 'CUENTA-A', ['id' => 'CUENTA-A', 'saldo' => 80], 1, $updateRecovery->id);
$visibleOldVersion = $txDatabase->find('cuentas', 'CUENTA-A');
if (($visibleOldVersion['saldo'] ?? null) !== 100 || ($visibleOldVersion['_version'] ?? null) !== 1) {
    throw new RuntimeException('datacore_prepared_update_hid_committed_version');
}
if ($transactions->recover() !== 1 || ($txDatabase->find('cuentas', 'CUENTA-A')['saldo'] ?? null) !== 80) {
    throw new RuntimeException('datacore_update_recovery_failed');
}

$deleteRecovery = $transactions->begin('delete-recovery')->delete('cuentas', 'CUENTA-B', 1);
file_put_contents(
    $journalPath,
    PhpSerializer::encode([
        'type' => 'PREPARED',
        'id' => $deleteRecovery->id,
        'operations' => $deleteRecovery->operations(),
        'at' => microtime(true),
    ]) . "\n",
    FILE_APPEND | LOCK_EX,
);
$txDatabase->delete('cuentas', 'CUENTA-B', 1, $deleteRecovery->id);
if (($txDatabase->find('cuentas', 'CUENTA-B')['saldo'] ?? null) !== 50) {
    throw new RuntimeException('datacore_prepared_delete_hid_committed_version');
}
if ($transactions->recover() !== 1 || $txDatabase->find('cuentas', 'CUENTA-B') !== null) {
    throw new RuntimeException('datacore_delete_recovery_failed');
}

if (extension_loaded('pcntl')) {
    $children = [];
    foreach (['CUENTA-E' => 30, 'CUENTA-F' => 40] as $accountId => $balance) {
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('datacore_concurrency_fork_failed');
        if ($pid === 0) {
            try {
                $childTransaction = $transactions->begin('concurrent-' . strtolower($accountId))
                    ->insert('cuentas', ['id' => $accountId, 'saldo' => $balance]);
                $transactions->commit($childTransaction);
                exit(0);
            } catch (Throwable) {
                exit(1);
            }
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException('datacore_concurrent_commit_failed');
        }
    }
    $concurrentStorage = new DataCoreTurbo($base . '/tx-storage', 1);
    $concurrentDatabase = (new DataCoreDatabase(
        $concurrentStorage,
        $txTypes,
        $base . '/tx-runtime-concurrent',
        $txKey,
    ))->collection('cuentas', 'Cuenta');
    new DataCoreTransactionManager($concurrentDatabase, $base . '/tx-journal');
    if ($concurrentDatabase->find('cuentas', 'CUENTA-E') === null
        || $concurrentDatabase->find('cuentas', 'CUENTA-F') === null) {
        throw new RuntimeException('datacore_concurrent_visibility_failed');
    }
    if ($transactions->recover() !== 0) {
        throw new RuntimeException('datacore_recovery_repeated_effects');
    }
}

foreach (['prepared', 'operation:0', 'before_committed', 'committed'] as $failureIndex => $failurePoint) {
    $transactionId = 'failure-' . $failureIndex;
    $firstId = 'FAIL-' . $failureIndex . '-A';
    $secondId = 'FAIL-' . $failureIndex . '-B';
    $failedTransaction = $transactions->begin($transactionId)
        ->insert('cuentas', ['id' => $firstId, 'saldo' => 10])
        ->insert('cuentas', ['id' => $secondId, 'saldo' => 20]);
    $transactions->failureProbe(
        static function (string $point) use ($failurePoint): void {
            if ($point === $failurePoint) throw new RuntimeException('simulated_transaction_crash');
        },
    );
    $throws(fn() => $transactions->commit($failedTransaction), 'simulated_transaction_crash');
    $transactions->failureProbe(null);

    if ($failurePoint !== 'committed') {
        $diagnostic = $transactions->pendingTransactions()[$transactionId] ?? null;
        $expectedState = match ($failurePoint) {
            'prepared' => 'prepared',
            'operation:0' => 'partially_applied',
            'before_committed' => 'fully_applied_uncommitted',
        };
        $expectedApplied = match ($failurePoint) {
            'prepared' => 0,
            'operation:0' => 1,
            'before_committed' => 2,
        };
        if (($diagnostic['state'] ?? null) !== $expectedState
            || ($diagnostic['applied_operations'] ?? null) !== $expectedApplied
            || ($diagnostic['total_operations'] ?? null) !== 2) {
            throw new RuntimeException('datacore_partial_commit_not_detected');
        }
    }

    $expectedRecoveries = $failurePoint === 'committed' ? 0 : 1;
    if ($transactions->recover() !== $expectedRecoveries) {
        throw new RuntimeException('datacore_failure_point_recovery_count_failed');
    }
    if ($txDatabase->find('cuentas', $firstId) === null || $txDatabase->find('cuentas', $secondId) === null) {
        throw new RuntimeException('datacore_failure_point_lost_batch');
    }
    $recordsAfterRecovery = $txStorage->getStats()['records'];
    if ($transactions->recover() !== 0 || $txStorage->getStats()['records'] !== $recordsAfterRecovery) {
        throw new RuntimeException('datacore_failure_point_duplicated_batch');
    }
}

$compactStorage = new DataCoreTurbo($base . '/compact-storage', 1);
$compactStorage->insert('records', ['id' => 'ACTIVE', 'value' => 1]);
$compactStorage->insert('records', ['id' => 'ACTIVE', 'value' => 2]);
$compactStorage->insert('records', ['id' => 'ACTIVE', 'value' => 3]);
$compactStorage->insert('records', ['id' => 'DELETED', 'value' => 1]);
$compactStorage->delete('records', 'DELETED');
$compactStorage->insert('records', ['id' => 'HELD', 'value' => 1, '_legal_hold' => true]);
$compactStorage->insert('records', ['id' => 'HELD', 'value' => 2, '_legal_hold' => true]);
$interruptedId = 'records-testcrash';
$interruptedStaging = $base . '/compact-storage/wal/compact-' . $interruptedId;
$interruptedBackup = $base . '/compact-storage/wal/backup-' . $interruptedId;
mkdir($interruptedStaging, 0700, true);
mkdir($interruptedBackup, 0700, true);
$interruptedSources = glob($base . '/compact-storage/data/records_*.bin') ?: [];
$interruptedNames = array_map('basename', $interruptedSources);
file_put_contents(
    $interruptedStaging . '/manifest.jahl',
    PhpSerializer::encode([
        'state' => 'PREPARED',
        'collection' => 'records',
        'source' => $interruptedNames,
        'staged' => $interruptedNames,
        'report' => [],
    ]),
    LOCK_EX,
);
foreach ($interruptedSources as $source) {
    rename($source, $interruptedBackup . '/' . basename($source));
    file_put_contents($source, 'partial-publication', LOCK_EX);
}
if ($compactStorage->recoverCompactions('records') !== 1) {
    throw new RuntimeException('datacore_compaction_recovery_not_detected');
}
if (($compactStorage->find('records', 'ACTIVE')['value'] ?? null) !== 3) {
    throw new RuntimeException('datacore_compaction_recovery_lost_data');
}
$previewCompact = $compactStorage->compactCollection('records', true, false);
if ($previewCompact['records_before'] !== 7 || $previewCompact['records_after'] !== 3 || $previewCompact['legal_hold_documents'] !== 1) throw new RuntimeException('datacore_compaction_preview_failed');
$compacted = $compactStorage->compactCollection('records', false, false);
if ($compacted['records_removed'] !== 4 || ($compactStorage->find('records', 'ACTIVE')['value'] ?? null) !== 3 || $compactStorage->find('records', 'DELETED') !== null || ($compactStorage->find('records', 'HELD')['value'] ?? null) !== 2) throw new RuntimeException('datacore_compaction_failed');
if ($compactStorage->getStats()['records'] !== 3) throw new RuntimeException('datacore_compaction_physical_count_failed');

echo "DATACORE DATABASE: PASS\n";
