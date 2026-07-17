<?php

declare(strict_types=1);

namespace Jah\JAS\Dispatch;

use Jah\JAS\Queue\PersistentJobQueue;
use Jah\JAS\Telemetry\MetricsRegistry;
use Jah\JAS\Worker\WorkerRegistry;
use Throwable;

final class JobDispatcher
{
    /** @var array<string,callable> */
    private array $executors = [];

    public function __construct(
        private readonly PersistentJobQueue $queue,
        private readonly WorkerRegistry $workers,
        private readonly ?MetricsRegistry $metrics = null
    ) {}

    public function registerExecutor(string $runtime, callable $executor): self
    {
        $this->executors[$runtime] = $executor;
        return $this;
    }

    public function dispatchOne(): ?array
    {
        $worker = null;
        $job = null;
        foreach ($this->workers->availableWorkers() as $candidate) {
            $leased = $this->queue->lease($candidate->id, $candidate->capabilities);
            if ($leased === null) continue;
            $worker = $this->workers->reserve($candidate->id);
            $job = $leased;
            break;
        }
        if ($worker === null || $job === null) return null;

        $started = hrtime(true);
        try {
            $executor = $this->executors[$worker->runtime] ?? null;
            if ($executor === null) {
                $this->queue->fail($job->id, $worker->id, 'executor_not_registered', false);
                return ['success'=>false,'job_id'=>$job->id,'error'=>'executor_not_registered'];
            }
            try {
                $result = $executor($job, $worker);
                $normalized = is_array($result) && array_key_exists('success', $result) ? $result : ['success'=>true,'result'=>$result];
                if (($normalized['success'] ?? false) === true) $this->queue->complete($job->id, $worker->id, $normalized);
                else $this->queue->fail($job->id, $worker->id, (string)($normalized['error'] ?? 'worker_failed'), true);
                $this->metrics?->increment(($normalized['success'] ?? false) ? 'jobs.completed' : 'jobs.failed');
                return $normalized + ['job_id'=>$job->id,'worker_id'=>$worker->id];
            } catch (Throwable $error) {
                $this->queue->fail($job->id, $worker->id, $error->getMessage(), true);
                $this->metrics?->increment('jobs.failed');
                return ['success'=>false,'job_id'=>$job->id,'worker_id'=>$worker->id,'error'=>$error->getMessage()];
            }
        } finally {
            $this->workers->release($worker->id);
            $this->metrics?->observe('dispatch.duration_ms', (hrtime(true)-$started)/1_000_000);
        }
    }

    public function drain(int $maxJobs = 1000): array
    {
        $results = [];
        for ($i=0; $i<$maxJobs; $i++) {
            try { $result = $this->dispatchOne(); }
            catch (Throwable $e) { if (str_contains($e->getMessage(), 'No hay worker')) break; throw $e; }
            if ($result === null) break;
            $results[] = $result;
        }
        return $results;
    }
}
