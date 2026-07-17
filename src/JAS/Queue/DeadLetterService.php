<?php

declare(strict_types=1);

namespace Jah\JAS\Queue;

use Jah\DataCore\PhpSerializer;
use Jah\JAS\Persistence\AuditJournal;
use Jah\JAS\Security\DualControlStore;
use Jah\JAS\Telemetry\MetricsRegistry;
use RuntimeException;

final class DeadLetterService
{
    private const ACTION = 'queue.dead_letter.reprocess';

    public function __construct(
        private readonly PersistentJobQueue $queue,
        private readonly DualControlStore $approvals,
        private readonly ?AuditJournal $audit = null,
        private readonly ?MetricsRegistry $metrics = null,
    ) {}

    /** @return list<array> */
    public function list(int $limit = 100): array
    {
        return array_map(static fn(Job $job): array => $job->toArray(), $this->queue->deadLetters($limit));
    }

    /** @return array{approval_id:string,job_id:string,request_id:string,fingerprint:string} */
    public function request(string $jobId, string $requesterId, string $requestId, int $ttlSeconds = 1_800): array
    {
        $this->validateActor($requesterId);
        $this->validateRequest($requestId);
        $job = $this->failed($jobId);
        $fingerprint = $this->fingerprint($job);
        $approval = $this->approvals->request(self::ACTION, $requesterId, $requestId, $fingerprint, $ttlSeconds);
        $this->metrics?->increment('queue.dead_letter.reprocess_requested');
        return ['approval_id' => $approval, 'job_id' => $jobId, 'request_id' => $requestId, 'fingerprint' => $fingerprint];
    }

    public function approve(string $approvalId, string $approverId): array
    {
        $this->validateActor($approverId);
        return $this->approvals->approve($approvalId, $approverId);
    }

    public function reprocess(string $jobId, string $approvalId, string $requestId, string $actorId): Job
    {
        $this->validateActor($actorId);
        $this->validateRequest($requestId);
        $job = $this->failed($jobId);
        $fingerprint = $this->fingerprint($job);
        $state = $this->approvals->state()[$approvalId] ?? throw new RuntimeException('dual_control_not_found');
        if (!hash_equals((string) ($state['approver_id'] ?? ''), $actorId)) throw new RuntimeException('dead_letter_actor_mismatch');
        $sameContext = ($state['action'] ?? null) === self::ACTION
            && ($state['request_id'] ?? null) === $requestId
            && hash_equals((string) ($state['payload_fingerprint'] ?? ''), $fingerprint);
        if (!$sameContext) throw new RuntimeException('dual_control_context_mismatch');
        $existing = $this->queue->reprocessed($jobId, $approvalId);
        if ($existing !== null) return $existing;
        if (($state['status'] ?? null) === 'approved') {
            $this->approvals->consume($approvalId, self::ACTION, $requestId, $fingerprint);
        } elseif (($state['status'] ?? null) !== 'consumed') {
            throw new RuntimeException('dual_control_not_approved');
        }
        $reprocessed = $this->queue->reprocessFailed($jobId, $approvalId);
        $this->audit?->record($actorId, self::ACTION, $requestId, true, $fingerprint);
        $this->metrics?->increment('queue.dead_letter.reprocessed');
        return $reprocessed;
    }

    private function failed(string $jobId): Job
    {
        if ($jobId === '' || strlen($jobId) > 128) throw new RuntimeException('job_id_invalid');
        $job = $this->queue->get($jobId) ?? throw new RuntimeException('job_not_found');
        if ($job->state !== Job::FAILED) throw new RuntimeException('dead_letter_not_failed');
        return $job;
    }

    private function fingerprint(Job $job): string
    {
        return hash('sha256', PhpSerializer::encode([
            'id' => $job->id, 'action' => $job->action, 'payload' => $job->payload,
            'capability' => $job->capability, 'priority' => $job->priority,
            'max_attempts' => $job->maxAttempts, 'attempts' => $job->attempts,
            'object_id' => $job->objectId, 'deduplication_key' => $job->deduplicationKey,
            'error' => $job->error, 'state' => $job->state,
        ]));
    }

    private function validateActor(string $actorId): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]{2,127}$/', $actorId) !== 1) throw new RuntimeException('dead_letter_actor_invalid');
    }

    private function validateRequest(string $requestId): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,127}$/', $requestId) !== 1) throw new RuntimeException('dead_letter_request_invalid');
    }
}
