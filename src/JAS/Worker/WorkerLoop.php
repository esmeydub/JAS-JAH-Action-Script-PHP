<?php

declare(strict_types=1);

namespace Jah\JAS\Worker;

use Jah\JAS\Queue\Job;
use Jah\JAS\Queue\PersistentJobQueue;
use Jah\JAS\Telemetry\MetricsRegistry;
use Throwable;

final class WorkerLoop
{
    private bool $running = false;

    /** @param callable(Job):mixed $executor */
    public function __construct(
        private readonly WorkerDescriptor $worker,
        private readonly PersistentJobQueue $queue,
        private $executor,
        private readonly ?MetricsRegistry $metrics = null,
        private readonly int $idleSleepMicroseconds = 100_000,
        private readonly int $leaseSeconds = 30
    ) {}

    public function run(?int $maxJobs = null): int
    {
        $this->running = true;
        $processed = 0;
        while ($this->shouldContinue() && ($maxJobs === null || $processed < $maxJobs)) {
            $this->worker->lastHeartbeat = time();
            $job = $this->queue->lease($this->worker->id, $this->worker->capabilities, $this->leaseSeconds);
            if ($job === null) {
                if ($maxJobs !== null) break;
                usleep(max(1_000, $this->idleSleepMicroseconds));
                continue;
            }

            $started = hrtime(true);
            try {
                $result = ($this->executor)($job);
                $normalized = is_array($result) && array_key_exists('success', $result) ? $result : ['success'=>true,'result'=>$result];
                if (($normalized['success'] ?? false) === true) {
                    $this->queue->complete($job->id, $this->worker->id, $normalized);
                    $this->metrics?->increment('worker.jobs.completed');
                } else {
                    $this->queue->fail($job->id, $this->worker->id, (string)($normalized['error'] ?? 'worker_failed'), true);
                    $this->metrics?->increment('worker.jobs.failed');
                }
            } catch (Throwable $error) {
                $this->queue->fail($job->id, $this->worker->id, $error->getMessage(), true);
                $this->metrics?->increment('worker.jobs.failed');
            } finally {
                $this->metrics?->observe('worker.job.duration_ms', (hrtime(true)-$started)/1_000_000);
            }
            $processed++;
        }
        $this->running = false;
        return $processed;
    }

    public function stop(): void { $this->running = false; }

    private function shouldContinue(): bool { return $this->running; }
}
