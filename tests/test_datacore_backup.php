<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/support.php';

use Jah\DataCore\DataCoreBackupService;
use Jah\DataCore\DataCoreContinuityLock;
use Jah\DataCore\DataCoreTurbo;
use Jah\JAS\Security\KeyRing;

$throws = static function (callable $operation, string $expected): void {
    try {
        $operation();
    } catch (Throwable $error) {
        if ($error->getMessage() === $expected) return;
        throw $error;
    }
    throw new RuntimeException('Expected ' . $expected);
};

$base = sys_get_temp_dir() . '/jas_backup_' . bin2hex(random_bytes(6));
$source = $base . '/source';
$backups = $base . '/backups';
$restore = $base . '/restore';
mkdir($source . '/storage/data', 0700, true);
mkdir($source . '/runtime/indexes', 0700, true);
file_put_contents($source . '/storage/data/personas_00.bin', "registro-seguro\0binario", LOCK_EX);
file_put_contents($source . '/runtime/indexes/personas.jahi', "indice-jah\n", LOCK_EX);

$keys = new KeyRing(['backup-key-2026' => random_bytes(32)], 'backup-key-2026');
$continuity = new DataCoreContinuityLock($base . '/continuity/snapshot.lock');
$storage = (new DataCoreTurbo($source . '/datacore', 1_000))->continuityLock($continuity);
$storage->insert('expedientes', ['id' => 'EXP-BACKUP-1', 'estado' => 'confirmado']);
$now = 1_800_000_000.0;
$service = new DataCoreBackupService(
    $source,
    $backups,
    $keys,
    $continuity,
    flushers: [$storage->flush(...)],
    clock: static function () use (&$now): float { return $now; },
);

try {
    $created = $service->create('backup-inicial');
    if (($created['files'] ?? 0) < 4 || !$service->verify('backup-inicial')) {
        throw new RuntimeException('datacore_backup_create_verify_failed');
    }
    file_put_contents($source . '/storage/data/personas_00.bin', 'estado-posterior', LOCK_EX);
    $restored = $service->restore('backup-inicial', $restore);
    if (($restored['files'] ?? null) !== ($created['files'] ?? null)
        || file_get_contents($restore . '/storage/data/personas_00.bin') !== "registro-seguro\0binario"
        || file_get_contents($restore . '/runtime/indexes/personas.jahi') !== "indice-jah\n") {
        throw new RuntimeException('datacore_backup_restore_content_failed');
    }
    $restoredStorage = new DataCoreTurbo($restore . '/datacore', 1);
    if (($restoredStorage->find('expedientes', 'EXP-BACKUP-1')['estado'] ?? null) !== 'confirmado') {
        throw new RuntimeException('datacore_backup_restored_database_unreadable');
    }
    file_put_contents($source . '/runtime/indexes/personas.jahi', "indice-posterior\n", LOCK_EX);
    $now += 10;
    $service->create('backup-posterior');
    $pointRestore = $base . '/restore-point';
    $pointResult = $service->restorePointInTime((float) $created['point_in_time'], $pointRestore);
    if (($pointResult['id'] ?? null) !== 'backup-inicial'
        || file_get_contents($pointRestore . '/runtime/indexes/personas.jahi') !== "indice-jah\n") {
        throw new RuntimeException('datacore_backup_point_in_time_failed');
    }
    $now += 120;
    $retentionPreview = $service->prune(1, 60, true);
    if (($retentionPreview['expired'] ?? null) !== ['backup-inicial']
        || ($retentionPreview['deleted'] ?? null) !== 0
        || ($retentionPreview['dry_run'] ?? null) !== true) {
        throw new RuntimeException('datacore_backup_retention_preview_failed');
    }
    $retentionApplied = $service->prune(1, 60, false);
    if (($retentionApplied['deleted'] ?? null) !== 1
        || is_file($backups . '/backup-inicial.jahb')) {
        throw new RuntimeException('datacore_backup_retention_apply_failed');
    }
    $throws(
        fn() => $service->restore('backup-inicial', $restore),
        'datacore_restore_destination_not_empty',
    );

    $archivePath = $backups . '/backup-posterior.jahb';
    $archive = (string) file_get_contents($archivePath);
    $offset = intdiv(strlen($archive), 2);
    $archive[$offset] = chr(ord($archive[$offset]) ^ 1);
    file_put_contents($archivePath, $archive, LOCK_EX);
    if ($service->verify('backup-posterior')) {
        throw new RuntimeException('datacore_backup_tamper_accepted');
    }

    $throws(
        fn() => new DataCoreBackupService($source, $source . '/nested-backups', $keys, $continuity),
        'datacore_backup_directory_inside_source',
    );
    echo "DATACORE BACKUP: PASS\n";
} finally {
    jas_test_remove_tree($base);
}
