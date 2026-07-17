<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/support.php';

use Jah\DataCore\PhpSerializer;
use Jah\JAS\Cluster\NodeIdentity;
use Jah\JAS\Observability\SustainedOperationEvidence;

$root = sys_get_temp_dir() . '/jas_sustained_' . bin2hex(random_bytes(6));
$identity = NodeIdentity::loadOrCreate($root . '/identity', 'test-node', 'local://operations');
$evidence = new SustainedOperationEvidence($root . '/campaign', $identity, 604_800, 3_600, 3_600);
$start = 1_800_000_000;
$manifest = $evidence->start($start);
$observation = [
    'operations' => 100, 'accepted' => 90, 'rejected' => 10,
    'integrity_valid' => true, 'recovery_valid' => true, 'readiness' => true,
    'queue_bounded' => true, 'disk_level' => 'normal',
];
for ($at = $start; $at <= $start + 604_800; $at += 3_600) {
    $sample = $evidence->record($observation, $at);
    if (!$sample['recorded'] || !$sample['sample_ok']) throw new RuntimeException('sustained_sample_rejected');
}
$complete = $evidence->status($start + 604_800);
if (!$complete['complete'] || !$complete['verified'] || $complete['remaining_seconds'] !== 0
    || $complete['sample_count'] !== 169) throw new RuntimeException('sustained_campaign_not_completed');

$badRoot = $root . '/bad-gap';
$bad = new SustainedOperationEvidence($badRoot, $identity, 604_800, 60, 300);
$bad->record($observation, $start);
$late = $bad->record($observation, $start + 301);
if ($late['sample_ok'] || $bad->status($start + 301)['complete']) throw new RuntimeException('sustained_gap_not_preserved');

$rollbackRoot = $root . '/rollback';
$rollback = new SustainedOperationEvidence($rollbackRoot, $identity);
$rollback->record($observation, $start);
try {
    $rollback->record($observation, $start - 1);
    throw new RuntimeException('sustained_clock_rollback_accepted');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'operations_evidence_clock_rollback') throw $error;
}

$sampleFile = $root . '/campaign/samples.jahl';
$lines = file($sampleFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$entry = PhpSerializer::decode($lines[10]);
$entry['observation']['accepted'] = 100;
$lines[10] = PhpSerializer::encode($entry);
file_put_contents($sampleFile, implode("\n", $lines) . "\n");
try {
    $evidence->status($start + 604_800);
    throw new RuntimeException('sustained_tampering_accepted');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'operations_evidence_sample_signature_invalid') throw $error;
}

try {
    new SustainedOperationEvidence($root . '/short', $identity, 604_799);
    throw new RuntimeException('sustained_short_campaign_accepted');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'operations_evidence_duration_invalid') throw $error;
}

jas_test_remove_tree($root);
echo "JAS SUSTAINED OPERATIONS EVIDENCE: PASS\n";
