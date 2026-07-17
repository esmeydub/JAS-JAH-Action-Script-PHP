<?php

declare(strict_types=1);

namespace Jah\JAS\Queue;

use Jah\JAS\Telemetry\MetricsRegistry;

final class JobService
{
    public function __construct(
        private readonly PersistentJobQueue $queue,
        private readonly ?MetricsRegistry $metrics = null
    ) {}

    public function submit(array $payload): array
    {
        $job = Job::create(
            action: (string)($payload['action'] ?? ''),
            payload: (array)($payload['payload'] ?? []),
            capability: (string)($payload['capability'] ?? ('action.' . (string)($payload['action'] ?? 'unknown'))),
            priority: (int)($payload['priority'] ?? 0),
            maxAttempts: (int)($payload['max_attempts'] ?? 3),
            objectId: isset($payload['object_id']) ? (string)$payload['object_id'] : null,
            deduplicationKey: isset($payload['deduplication_key']) ? (string)$payload['deduplication_key'] : null
        );
        $stored = $this->queue->submit($job);
        $this->metrics?->increment('jobs.submitted');
        $this->metrics?->gauge('queue.queued', (int)($this->queue->stats()['states'][Job::QUEUED] ?? 0));
        return ['success'=>true,'job'=>$stored->toArray()];
    }

    public function status(string $jobId): array
    {
        $job = $this->queue->get($jobId);
        return $job ? ['success'=>true,'job'=>$job->toArray()] : ['success'=>false,'error'=>'job_not_found'];
    }

    public function cancel(string $jobId): array
    {
        $this->queue->cancel($jobId);
        $this->metrics?->increment('jobs.cancelled');
        return ['success'=>true,'job_id'=>$jobId,'state'=>Job::CANCELLED];
    }

    public function stats(): array
    {
        return ['success'=>true,'queue'=>$this->queue->stats()];
    }
}
