<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Observability\JahLogger;
use Jah\JAS\Observability\RetentionScheduler;
use Jah\JAS\Persistence\OutboxJournal;
use Jah\JAS\Persistence\WalJournal;

$root = sys_get_temp_dir() . '/jas_retention_' . bin2hex(random_bytes(6));
$throws = static function (callable $operation, string $expected): void {
    try { $operation(); } catch (Throwable $error) { if ($error->getMessage() === $expected) return; throw $error; }
    throw new RuntimeException('Expected ' . $expected);
};

$wal = new WalJournal($root . '/wal');
$wal->begin('tx-finished', 'document.store', ['id' => 'DONE']);
$wal->commit('tx-finished');
$wal->begin('tx-pending', 'document.store', ['id' => 'PENDING']);
$walPreview = $wal->compact();
if ($walPreview['entries_before'] !== 3 || $walPreview['entries_after'] !== 1 || $walPreview['compacted']) {
    throw new RuntimeException('retention_wal_preview_failed');
}
$walApplied = $wal->compact(false);
if (!$walApplied['compacted'] || array_keys($wal->pending()) !== ['tx-pending']) throw new RuntimeException('retention_wal_pending_lost');

$outbox = new OutboxJournal($root . '/outbox');
$outbox->prepare('request-finished', 'document.store', ['id' => 'DONE']);
$outbox->applied('request-finished');
$outbox->prepare('request-pending', 'document.store', ['id' => 'PENDING']);
$outboxPreview = $outbox->compact();
if ($outboxPreview['entries_before'] !== 3 || $outboxPreview['entries_after'] !== 1 || $outboxPreview['compacted']) {
    throw new RuntimeException('retention_outbox_preview_failed');
}
$outbox->compact(false);
if (array_keys($outbox->pending()) !== ['request-pending']) throw new RuntimeException('retention_outbox_pending_lost');

$logPath = $root . '/logs/app.jahl';
$logger = new JahLogger($logPath);
for ($index = 0; $index < 20; $index++) $logger->log('info', 'retention.record', ['index' => $index, 'payload' => str_repeat('x', 100)]);
$beforeHash = hash_file('sha256', $logPath);
$logPreview = $logger->rotate(1_024, 2, 3_600, true, 1_800_000_000);
if (!$logPreview['rotated'] || !is_file($logPath) || hash_file('sha256', $logPath) !== $beforeHash) {
    throw new RuntimeException('retention_log_preview_mutated');
}
$logApplied = $logger->rotate(1_024, 2, 3_600, false, 1_800_000_000);
$archives = glob($logPath . '.archive.*.jahl') ?: [];
if (!$logApplied['rotated'] || count($archives) !== 1 || is_file($logPath)) throw new RuntimeException('retention_log_rotation_failed');
$logger->log('notice', 'retention.continues');
if (count($logger->records()) !== 1) throw new RuntimeException('retention_log_resume_failed');

$runs = 0;
$scheduler = (new RetentionScheduler($root . '/maintenance', 60))->task(
    'test.retention',
    static function (bool $apply) use (&$runs): array { $runs++; return ['applied' => $apply]; },
);
$dry = $scheduler->run(false, false, 1_800_000_000);
if (!$dry['due'] || $dry['applied'] || $runs !== 1) throw new RuntimeException('retention_scheduler_preview_failed');
$applied = $scheduler->run(true, false, 1_800_000_000);
if (!$applied['applied'] || $applied['last_success_at'] !== 1_800_000_000 || $runs !== 2) throw new RuntimeException('retention_scheduler_apply_failed');
$skipped = $scheduler->run(true, false, 1_800_000_030);
if ($skipped['due'] || $runs !== 2) throw new RuntimeException('retention_scheduler_interval_failed');
$scheduler->run(true, true, 1_800_000_031);
if ($runs !== 3) throw new RuntimeException('retention_scheduler_force_failed');

$walFile = $root . '/wal/jas.wal';
file_put_contents($walFile, "corrupt\n", FILE_APPEND);
$walCorruptHash = hash_file('sha256', $walFile);
$throws(static fn() => $wal->compact(false), 'wal_corrupt');
if (hash_file('sha256', $walFile) !== $walCorruptHash) throw new RuntimeException('retention_replaced_corrupt_wal');

file_put_contents($logPath, "corrupt\n", FILE_APPEND);
$logCorruptHash = hash_file('sha256', $logPath);
$throws(static fn() => $logger->rotate(1_024, 2, 3_600, false), 'logger_record_corrupt');
if (hash_file('sha256', $logPath) !== $logCorruptHash) throw new RuntimeException('retention_replaced_corrupt_log');

echo "JAS RETENTION: PASS\n";
