<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/tests/support.php';

use Jah\DataCore\DataCoreBackupService;
use Jah\DataCore\DataCoreContinuityLock;
use Jah\DataCore\DataCoreTurbo;
use Jah\JAS\Security\KeyRing;

function backupDirectoryBytes(string $path): int
{
    $bytes = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $entry) if ($entry->isFile()) $bytes += $entry->getSize();
    return $bytes;
}

function timed(callable $operation): array
{
    $started = hrtime(true);
    $result = $operation();
    return ['seconds' => (hrtime(true) - $started) / 1_000_000_000, 'result' => $result];
}

$records = isset($argv[1]) ? filter_var($argv[1], FILTER_VALIDATE_INT) : 5_000;
if (!is_int($records) || $records < 100 || $records > 100_000) {
    throw new RuntimeException('backup_benchmark_records_invalid');
}
$base = sys_get_temp_dir() . '/jas_backup_benchmark_' . bin2hex(random_bytes(5));
$source = $base . '/source';
$backups = $base . '/backups';
$restore = $base . '/restore';
mkdir($source, 0700, true);
$lock = new DataCoreContinuityLock($base . '/continuity.lock');
$storage = (new DataCoreTurbo($source . '/storage', 500))->continuityLock($lock);

try {
    for ($number = 0; $number < $records; $number++) {
        $storage->insert('benchmark', [
            'id' => 'DOC-' . $number,
            'sequence' => $number,
            'payload' => str_repeat(hash('sha256', (string) $number), 4),
        ]);
    }
    $storage->flush();
    $sourceBytes = backupDirectoryBytes($source);
    $service = new DataCoreBackupService(
        $source,
        $backups,
        new KeyRing(['benchmark-key' => random_bytes(32)], 'benchmark-key'),
        $lock,
        flushers: [$storage->flush(...)],
    );
    $create = timed(fn(): array => $service->create('benchmark'));
    $verify = timed(fn(): bool => $service->verify('benchmark'));
    $restoreResult = timed(fn(): array => $service->restore('benchmark', $restore));
    $restored = new DataCoreTurbo($restore . '/storage', 1);
    if ($verify['result'] !== true
        || ($restored->find('benchmark', 'DOC-' . ($records - 1))['sequence'] ?? null) !== $records - 1) {
        throw new RuntimeException('backup_benchmark_correctness_failed');
    }
    $archiveBytes = (int) filesize($backups . '/benchmark.jahb');
    printf("JAS DataCore backup benchmark\n");
    printf("PHP: %s | OS: %s | records: %d\n", PHP_VERSION, PHP_OS_FAMILY, $records);
    printf("Source bytes: %d | archive bytes: %d\n", $sourceBytes, $archiveBytes);
    printf("Create seconds: %.6f\n", $create['seconds']);
    printf("Verify seconds: %.6f\n", $verify['seconds']);
    printf("Restore seconds: %.6f\n", $restoreResult['seconds']);
    echo "Result: PASS; restored DataCore lookup matched.\n";
} finally {
    jas_test_remove_tree($base);
}
