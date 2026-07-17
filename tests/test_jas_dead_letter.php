<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Persistence\AuditJournal;
use Jah\JAS\Queue\DeadLetterService;
use Jah\JAS\Queue\Job;
use Jah\JAS\Queue\PersistentJobQueue;
use Jah\JAS\Security\DualControlStore;
use Jah\JAS\Telemetry\MetricsRegistry;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$throws = static function (callable $operation, string $expected): void {
    try {
        $operation();
    } catch (Throwable $error) {
        if ($error->getMessage() === $expected) return;
        throw $error;
    }
    throw new RuntimeException('Expected ' . $expected);
};

$root = sys_get_temp_dir() . '/jas_dead_letter_' . bin2hex(random_bytes(6));
$queue = new PersistentJobQueue($root . '/queue', 10, 1);
$approvals = new DualControlStore($root . '/approvals');
$audit = new AuditJournal($root . '/audit');
$metrics = new MetricsRegistry($root . '/metrics');
$service = new DeadLetterService($queue, $approvals, $audit, $metrics);

$failed = $queue->submit(Job::create('tramite.publicar', ['id' => 'T-100', 'clasificacion' => 'reservada'], 'tramites.publish', maxAttempts: 1));
$lease = $queue->lease('worker-secure', ['tramites.*']);
$assert($lease?->id === $failed->id, 'dead_letter_lease_failed');
$throws(fn() => $queue->fail($failed->id, 'worker-secure', str_repeat('x', 4_097), false), 'job_error_invalid');
$queue->fail($failed->id, 'worker-secure', 'upstream_policy_rejected', false);
$stored = $queue->get($failed->id);
$assert($stored?->state === Job::FAILED && $stored->attempts === 1 && $stored->error === 'upstream_policy_rejected', 'dead_letter_context_lost');
$assert(count($service->list()) === 1 && ($queue->stats()['dead_letters'] ?? 0) === 1, 'dead_letter_not_visible');
$throws(fn() => $service->list(0), 'dead_letter_limit_invalid');

$active = $queue->submit(Job::create('tramite.consultar', ['id' => 'T-100'], 'tramites.read'));
$throws(fn() => $service->request($active->id, 'operator.one', 'req-dlq-active'), 'dead_letter_not_failed');
$request = $service->request($failed->id, 'operator.one', 'req-dlq-0001');
$approvalId = $request['approval_id'];
$throws(fn() => $service->approve($approvalId, 'operator.one'), 'dual_control_same_actor_forbidden');
$service->approve($approvalId, 'supervisor.one');
$throws(fn() => $service->reprocess($failed->id, $approvalId, 'req-dlq-0001', 'intruder.one'), 'dead_letter_actor_mismatch');
$throws(fn() => $service->reprocess($failed->id, $approvalId, 'req-dlq-wrong', 'supervisor.one'), 'dual_control_context_mismatch');

$reprocessed = $service->reprocess($failed->id, $approvalId, 'req-dlq-0001', 'supervisor.one');
$assert($reprocessed->state === Job::QUEUED && $reprocessed->attempts === 0
    && $reprocessed->originJobId === $failed->id && $reprocessed->reprocessApprovalId === $approvalId
    && $reprocessed->payload === $failed->payload && $reprocessed->capability === $failed->capability, 'dead_letter_reprocess_context_lost');
$again = $service->reprocess($failed->id, $approvalId, 'req-dlq-0001', 'supervisor.one');
$assert($again->id === $reprocessed->id, 'dead_letter_reprocess_not_idempotent');
$throws(fn() => $service->reprocess($failed->id, $approvalId, 'req-dlq-0001', 'other.supervisor'), 'dead_letter_actor_mismatch');
$assert($queue->get($failed->id)?->state === Job::FAILED, 'dead_letter_original_mutated');

$queue->cancel($active->id);
$replayLease = $queue->lease('worker-secure', ['tramites.*']);
$assert($replayLease?->id === $reprocessed->id, 'dead_letter_reprocess_not_dispatchable');
$queue->complete($reprocessed->id, 'worker-secure', ['success' => true]);
$queue->compact(false);
$assert($queue->get($failed->id)?->state === Job::FAILED, 'dead_letter_compaction_deleted_original');
$assert($audit->verify(), 'dead_letter_audit_invalid');
$snapshot = $metrics->snapshot();
$assert(($snapshot['counters']['queue.dead_letter.reprocess_requested'] ?? 0) === 1
    && ($snapshot['counters']['queue.dead_letter.reprocessed'] ?? 0) === 1, 'dead_letter_metrics_missing');

// Una aprobación consumida antes de encontrar backpressure debe poder retomarse.
$smallQueue = new PersistentJobQueue($root . '/small-queue', 1, 1);
$smallApprovals = new DualControlStore($root . '/small-approvals');
$smallService = new DeadLetterService($smallQueue, $smallApprovals);
$smallFailed = $smallQueue->submit(Job::create('job.failed', ['value' => 1], 'jobs.run', maxAttempts: 1));
$smallLease = $smallQueue->lease('worker-small', ['jobs.*']);
$smallQueue->fail($smallFailed->id, (string) $smallLease?->workerId, 'terminal', false);
$blocking = $smallQueue->submit(Job::create('job.blocking', [], 'jobs.run'));
$smallRequest = $smallService->request($smallFailed->id, 'operator.two', 'req-dlq-0002');
$smallService->approve($smallRequest['approval_id'], 'supervisor.two');
$throws(
    fn() => $smallService->reprocess($smallFailed->id, $smallRequest['approval_id'], 'req-dlq-0002', 'supervisor.two'),
    'queue_full'
);
$smallQueue->cancel($blocking->id);
$resumed = $smallService->reprocess($smallFailed->id, $smallRequest['approval_id'], 'req-dlq-0002', 'supervisor.two');
$assert($resumed->originJobId === $smallFailed->id, 'dead_letter_consumed_approval_not_recoverable');

echo "JAS DEAD LETTER: PASS\n";
