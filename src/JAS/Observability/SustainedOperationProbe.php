<?php

declare(strict_types=1);

namespace Jah\JAS\Observability;

use Jah\DataCore\DataCoreTurbo;
use Jah\JAS\Persistence\AuditJournal;
use Jah\JAS\Persistence\EventJournal;
use Jah\JAS\Queue\Job;
use Jah\JAS\Queue\PersistentJobQueue;
use RuntimeException;

/** Bounded real workload used by the sustained-operation evidence campaign. */
final class SustainedOperationProbe
{
    private readonly string $lockFile;

    public function __construct(private readonly string $root, private readonly int $jobsPerSample = 20)
    {
        if ($jobsPerSample < 2 || $jobsPerSample > 1_000) throw new RuntimeException('operations_probe_size_invalid');
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('operations_probe_directory_failed');
        }
        $this->lockFile = rtrim($root, '/') . '/probe.lock';
    }

    /** @return array{operations:int,accepted:int,rejected:int,integrity_valid:bool,recovery_valid:bool,readiness:bool,queue_bounded:bool,disk_level:string} */
    public function run(): array
    {
        $lock = fopen($this->lockFile, 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('operations_probe_already_running');
        try {
            $disk = DiskPressureGuard::fromEnvironment($this->root)->report();
            $queuePath = rtrim($this->root, '/') . '/queue';
            $queue = new PersistentJobQueue($queuePath, 1_000, 30);
            $batch = bin2hex(random_bytes(8));
            for ($index = 0; $index < $this->jobsPerSample; $index++) {
                $domain = $index % 2 === 0 ? 'social' : 'payments';
                $queue->submit(Job::create(
                    $domain . '.operations_probe',
                    ['batch' => $batch, 'sequence' => $index],
                    $domain . '.probe',
                    deduplicationKey: $batch . ':' . $index,
                ));
            }
            $completed = 0;
            while (($job = $queue->lease('operations-probe', ['social.*', 'payments.*'])) !== null) {
                $queue->complete($job->id, 'operations-probe', ['verified' => true]);
                $completed++;
            }
            $stats = $queue->stats();

            $storagePath = rtrim($this->root, '/') . '/datacore';
            $storage = new DataCoreTurbo($storagePath, 1, DiskPressureGuard::fromEnvironment($this->root));
            $recordId = 'PROBE-' . strtoupper($batch);
            $storage->insert('operations_probe', ['id' => $recordId, 'batch' => $batch, 'completed_jobs' => $completed]);
            $stored = $storage->find('operations_probe', $recordId);

            $reopenedQueue = new PersistentJobQueue($queuePath, 1_000, 30);
            $reopenedStorage = new DataCoreTurbo($storagePath, 1);
            $recovered = $reopenedStorage->find('operations_probe', $recordId);
            $recoveryValid = is_array($stored) && is_array($recovered)
                && ($recovered['batch'] ?? null) === $batch
                && $reopenedQueue->stats()['states'][Job::COMPLETED] >= $completed;
            $integrityValid = (new EventJournal(dirname($this->root) . '/events'))->verify()
                && (new AuditJournal(dirname($this->root) . '/audit'))->verify();
            $queueBounded = $completed === $this->jobsPerSample
                && ($stats['states'][Job::QUEUED] ?? -1) === 0
                && ($stats['states'][Job::LEASED] ?? -1) === 0
                && ($stats['total'] ?? 1_001) <= ($stats['capacity'] ?? 0);
            $queue->compact(false);
            return [
                'operations' => $this->jobsPerSample + 1,
                'accepted' => $completed + (is_array($stored) ? 1 : 0),
                'rejected' => $this->jobsPerSample - $completed,
                'integrity_valid' => $integrityValid,
                'recovery_valid' => $recoveryValid,
                'readiness' => ($disk['ok'] ?? false) === true,
                'queue_bounded' => $queueBounded,
                'disk_level' => (string) ($disk['level'] ?? DiskPressureGuard::EMERGENCY),
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
