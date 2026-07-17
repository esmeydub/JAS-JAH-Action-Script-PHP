<?php

declare(strict_types=1);

namespace Jah\JAS\Queue;

use Jah\DataCore\PhpSerializer;
use Jah\DataCore\WriteAdmission;
use RuntimeException;

final class PersistentJobQueue
{
    private string $journal;
    private string $lockFile;

    public function __construct(
        string $directory,
        private readonly int $maxQueued = 10000,
        private readonly int $defaultLeaseSeconds = 30,
        private readonly ?WriteAdmission $writeAdmission = null,
    ) {
        if ($maxQueued < 1 || $defaultLeaseSeconds < 1) throw new RuntimeException('Configuración de cola inválida');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("No se pudo crear la cola: {$directory}");
        }
        $this->journal = rtrim($directory, '/') . '/jobs.journal';
        $this->lockFile = rtrim($directory, '/') . '/jobs.lock';
    }

    public function submit(Job $job): Job
    {
        return $this->locked(function () use ($job): Job {
            $jobs = $this->rebuildUnlocked();
            foreach ($jobs as $existing) {
                $duplicateActiveJob = $job->deduplicationKey !== null
                    && $existing->deduplicationKey === $job->deduplicationKey
                    && $existing->state !== Job::CANCELLED
                    && $existing->state !== Job::FAILED;
                if ($duplicateActiveJob) {
                    return $existing;
                }
            }
            $queued = count(array_filter($jobs, static fn(Job $item): bool => $item->state === Job::QUEUED || $item->state === Job::LEASED));
            if ($queued >= $this->maxQueued) throw new RuntimeException('queue_full');
            $this->appendUnlocked(['type'=>'SUBMIT','job'=>$job->toArray(),'at'=>microtime(true)]);
            return $job;
        });
    }

    public function lease(string $workerId, array $capabilities, ?int $leaseSeconds = null): ?Job
    {
        if ($workerId === '' || strlen($workerId) > 128) throw new RuntimeException('worker_id_invalid');
        if ($leaseSeconds !== null && $leaseSeconds < 1) throw new RuntimeException('lease_seconds_invalid');
        if ($capabilities === []) throw new RuntimeException('worker_capabilities_required');
        return $this->locked(function () use ($workerId, $capabilities, $leaseSeconds): ?Job {
            $this->recoverExpiredUnlocked();
            $jobs = $this->rebuildUnlocked();
            $candidates = array_filter($jobs, static fn(Job $job): bool => $job->state === Job::QUEUED && self::supports($capabilities, $job->capability));
            if ($candidates === []) return null;
            usort($candidates, static function (Job $a, Job $b): int {
                $priority = $b->priority <=> $a->priority;
                return $priority !== 0 ? $priority : ($a->createdAt <=> $b->createdAt);
            });
            $job = $candidates[0];
            $job->state = Job::LEASED;
            $job->workerId = $workerId;
            $job->attempts++;
            $job->leasedUntil = microtime(true) + ($leaseSeconds ?? $this->defaultLeaseSeconds);
            $this->appendUnlocked(['type'=>'LEASE','job_id'=>$job->id,'worker_id'=>$workerId,'attempts'=>$job->attempts,'leased_until'=>$job->leasedUntil,'at'=>microtime(true)]);
            return $job;
        });
    }

    public function complete(string $jobId, string $workerId, mixed $result): void
    {
        $this->transition($jobId, $workerId, 'COMPLETE', ['result'=>$result]);
    }

    public function fail(string $jobId, string $workerId, string $error, bool $retry = true): void
    {
        if ($error === '' || strlen($error) > 4_096 || str_contains($error, "\0")) throw new RuntimeException('job_error_invalid');
        $this->locked(function () use ($jobId, $workerId, $error, $retry): void {
            $jobs = $this->rebuildUnlocked();
            $job = $jobs[$jobId] ?? null;
            if (!$job) throw new RuntimeException('job_not_found');
            if ($job->state !== Job::LEASED || $job->workerId !== $workerId) throw new RuntimeException('job_lease_mismatch');
            $willRetry = $retry && $job->attempts < $job->maxAttempts;
            $this->appendUnlocked(['type'=>'FAIL','job_id'=>$jobId,'worker_id'=>$workerId,'error'=>$error,'retry'=>$willRetry,'at'=>microtime(true)]);
        });
    }

    public function cancel(string $jobId): void
    {
        $this->locked(function () use ($jobId): void {
            $jobs = $this->rebuildUnlocked();
            $job = $jobs[$jobId] ?? null;
            if (!$job) throw new RuntimeException('job_not_found');
            if (in_array($job->state, [Job::COMPLETED, Job::CANCELLED], true)) return;
            $this->appendUnlocked(['type'=>'CANCEL','job_id'=>$jobId,'at'=>microtime(true)]);
        });
    }

    public function get(string $jobId): ?Job
    {
        return $this->locked(fn(): ?Job => $this->rebuildUnlocked()[$jobId] ?? null);
    }

    /** @return array<string,Job> */
    public function all(): array
    {
        return $this->locked(fn(): array => $this->rebuildUnlocked());
    }

    /** @return list<Job> */
    public function deadLetters(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1_000) throw new RuntimeException('dead_letter_limit_invalid');
        $failed = array_values(array_filter($this->all(), static fn(Job $job): bool => $job->state === Job::FAILED));
        usort($failed, static fn(Job $left, Job $right): int => $right->createdAt <=> $left->createdAt);
        return array_slice($failed, 0, $limit);
    }

    public function reprocessed(string $originJobId, string $approvalId): ?Job
    {
        foreach ($this->all() as $job) {
            if ($job->originJobId === $originJobId && $job->reprocessApprovalId === $approvalId) return $job;
        }
        return null;
    }

    public function reprocessFailed(string $originJobId, string $approvalId): Job
    {
        if ($approvalId === '' || strlen($approvalId) > 128) throw new RuntimeException('dead_letter_approval_invalid');
        return $this->locked(function () use ($originJobId, $approvalId): Job {
            $jobs = $this->rebuildUnlocked();
            foreach ($jobs as $existing) {
                if ($existing->originJobId === $originJobId && $existing->reprocessApprovalId === $approvalId) return $existing;
            }
            $origin = $jobs[$originJobId] ?? throw new RuntimeException('job_not_found');
            if ($origin->state !== Job::FAILED) throw new RuntimeException('dead_letter_not_failed');
            $queued = count(array_filter($jobs, static fn(Job $item): bool => $item->state === Job::QUEUED || $item->state === Job::LEASED));
            if ($queued >= $this->maxQueued) throw new RuntimeException('queue_full');
            $job = new Job(
                bin2hex(random_bytes(16)), $origin->action, $origin->payload, $origin->capability,
                $origin->priority, $origin->maxAttempts, microtime(true), $origin->objectId,
                'dlq:' . hash('sha256', $originJobId . "\0" . $approvalId), $originJobId, $approvalId,
            );
            $this->appendUnlocked([
                'type' => 'REPROCESS', 'job' => $job->toArray(), 'origin_job_id' => $originJobId,
                'approval_id' => $approvalId, 'at' => microtime(true),
            ]);
            return $job;
        });
    }

    public function stats(): array
    {
        $jobs = $this->all();
        $counts = [Job::QUEUED=>0, Job::LEASED=>0, Job::COMPLETED=>0, Job::FAILED=>0, Job::CANCELLED=>0];
        foreach ($jobs as $job) $counts[$job->state] = ($counts[$job->state] ?? 0) + 1;
        return ['total'=>count($jobs), 'states'=>$counts, 'dead_letters'=>$counts[Job::FAILED], 'capacity'=>$this->maxQueued];
    }

    public function compact(bool $keepTerminal = true): void
    {
        $this->locked(function () use ($keepTerminal): void {
            $jobs = $this->rebuildUnlocked();
            $temp = $this->journal . '.compact.' . bin2hex(random_bytes(4));
            $h = fopen($temp, 'xb');
            if ($h === false) throw new RuntimeException('No se pudo crear compactación de cola');
            try {
                foreach ($jobs as $job) {
                    if (!$keepTerminal && in_array($job->state, [Job::COMPLETED, Job::CANCELLED], true)) continue;
                    $line = PhpSerializer::encode(['type'=>'SUBMIT','job'=>$job->toArray(),'at'=>microtime(true)]) . "\n";
                    if (fwrite($h, $line) !== strlen($line)) throw new RuntimeException('Compactación incompleta');
                }
                if (!fflush($h)) throw new RuntimeException('No se pudo sincronizar compactación');
                if (function_exists('fsync')) @fsync($h);
            } finally { fclose($h); }
            if (!rename($temp, $this->journal)) { @unlink($temp); throw new RuntimeException('No se pudo publicar compactación'); }
            @chmod($this->journal, 0600);
        });
    }

    public function recoverExpired(): int
    {
        return $this->locked(fn(): int => $this->recoverExpiredUnlocked());
    }

    private function transition(string $jobId, string $workerId, string $type, array $extra): void
    {
        $this->locked(function () use ($jobId, $workerId, $type, $extra): void {
            $jobs = $this->rebuildUnlocked();
            $job = $jobs[$jobId] ?? null;
            if (!$job) throw new RuntimeException('job_not_found');
            if ($job->state !== Job::LEASED || $job->workerId !== $workerId) throw new RuntimeException('job_lease_mismatch');
            $this->appendUnlocked(['type'=>$type,'job_id'=>$jobId,'worker_id'=>$workerId,'at'=>microtime(true)] + $extra);
        });
    }

    private function recoverExpiredUnlocked(): int
    {
        $now = microtime(true);
        $count = 0;
        foreach ($this->rebuildUnlocked() as $job) {
            if ($job->state !== Job::LEASED || $job->leasedUntil === null || $job->leasedUntil > $now) continue;
            $retry = $job->attempts < $job->maxAttempts;
            $this->appendUnlocked(['type'=>'EXPIRE','job_id'=>$job->id,'retry'=>$retry,'error'=>'lease_expired','at'=>$now]);
            $count++;
        }
        return $count;
    }

    /** @return array<string,Job> */
    private function rebuildUnlocked(): array
    {
        $jobs = [];
        if (!is_file($this->journal)) return $jobs;
        $h = fopen($this->journal, 'rb');
        if ($h === false) throw new RuntimeException('No se pudo leer journal de cola');
        try {
            while (($line = fgets($h)) !== false) {
                $event = PhpSerializer::decode(trim($line));
                if (!is_array($event) || !isset($event['type'])) continue;
                $type = (string)$event['type'];
                if (($type === 'SUBMIT' || $type === 'REPROCESS') && is_array($event['job'] ?? null)) {
                    try {
                        $job = Job::fromArray($event['job']);
                    } catch (\InvalidArgumentException $e) {
                        throw new RuntimeException('queue_journal_corrupt', 0, $e);
                    }
                    $jobs[$job->id] = $job;
                    continue;
                }
                $id = (string)($event['job_id'] ?? '');
                if ($id === '' || !isset($jobs[$id])) continue;
                $job = $jobs[$id];
                if ($type === 'LEASE') {
                    if (!isset($event['worker_id'], $event['attempts'], $event['leased_until'])) {
                        throw new RuntimeException('queue_journal_corrupt');
                    }
                    $job->state = Job::LEASED;
                    $job->workerId = (string)$event['worker_id'];
                    $job->attempts = (int)$event['attempts'];
                    $job->leasedUntil = (float)$event['leased_until'];
                } elseif ($type === 'COMPLETE') {
                    $job->state = Job::COMPLETED; $job->result = $event['result'] ?? null; $job->workerId = null; $job->leasedUntil = null;
                } elseif ($type === 'FAIL' || $type === 'EXPIRE') {
                    $retry = (bool)($event['retry'] ?? false);
                    $job->state = $retry ? Job::QUEUED : Job::FAILED;
                    $job->error = (string)($event['error'] ?? 'job_failed');
                    $job->workerId = null; $job->leasedUntil = null;
                } elseif ($type === 'CANCEL') {
                    $job->state = Job::CANCELLED; $job->workerId = null; $job->leasedUntil = null;
                }
            }
        } finally { fclose($h); }
        return $jobs;
    }

    private function appendUnlocked(array $event): void
    {
        $encoded = PhpSerializer::encode($event) . "\n";
        $essential = in_array((string) ($event['type'] ?? ''), ['LEASE', 'COMPLETE', 'FAIL', 'EXPIRE', 'CANCEL'], true);
        $this->writeAdmission?->assertWritable('queue.journal.append', strlen($encoded), $essential);
        $h = fopen($this->journal, 'ab');
        if ($h === false) throw new RuntimeException('No se pudo abrir journal de cola');
        try {
            $written = fwrite($h, $encoded);
            if ($written !== strlen($encoded) || !fflush($h)) throw new RuntimeException('Escritura incompleta en cola');
            if (function_exists('fsync')) @fsync($h);
        } finally { fclose($h); }
    }

    private function locked(callable $callback): mixed
    {
        $h = fopen($this->lockFile, 'c+b');
        if ($h === false) throw new RuntimeException('No se pudo abrir lock de cola');
        try {
            if (!flock($h, LOCK_EX)) throw new RuntimeException('No se pudo bloquear cola');
            return $callback();
        } finally { flock($h, LOCK_UN); fclose($h); }
    }

    private static function supports(array $capabilities, string $required): bool
    {
        foreach ($capabilities as $capability) {
            $capability = (string)$capability;
            if ($capability === '' || strlen($capability) > 255) continue;
            if ($capability === '*' || $capability === $required || (str_ends_with($capability, '.*') && str_starts_with($required, substr($capability, 0, -1)))) return true;
        }
        return false;
    }
}
