<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\DataCore\DataCoreTurbo;
use Jah\JAS\Observability\DiskPressureGuard;
use Jah\JAS\Queue\Job;
use Jah\JAS\Queue\PersistentJobQueue;

$throws = static function (callable $operation, string $expected): void {
    try { $operation(); } catch (Throwable $error) { if ($error->getMessage() === $expected) return; throw $error; }
    throw new RuntimeException('Expected ' . $expected);
};

$free = 8_000;
$alerts = [];
$guard = new DiskPressureGuard(
    '/virtual', 4_000, 2_000, 500,
    static function (string $path) use (&$free): array { return ['total' => 10_000, 'free' => $free]; },
    static function (array $alert) use (&$alerts): void { $alerts[] = $alert; },
);
if ($guard->report()['level'] !== DiskPressureGuard::NORMAL || $alerts !== []) throw new RuntimeException('disk_normal_invalid');

$free = 3_500;
if ($guard->report()['level'] !== DiskPressureGuard::WARNING || count($alerts) !== 1
    || $alerts[0]['current'] !== DiskPressureGuard::WARNING) throw new RuntimeException('disk_warning_alert_failed');
$guard->report();
if (count($alerts) !== 1) throw new RuntimeException('disk_alert_not_debounced');
$throws(static fn() => $guard->assertWritable('queue.submit', 1_600), 'disk_pressure_write_rejected');

$free = 1_500;
if ($guard->report()['level'] !== DiskPressureGuard::CRITICAL || count($alerts) !== 2) throw new RuntimeException('disk_critical_alert_failed');
$throws(static fn() => $guard->assertWritable('queue.submit', 100), 'disk_pressure_write_rejected');
$guard->assertWritable('queue.complete', 100, true);

$root = sys_get_temp_dir() . '/jas_disk_' . bin2hex(random_bytes(6));
$free = 8_000;
$queue = new PersistentJobQueue($root . '/queue', 10, 30, $guard);
$job = new Job('disk-job-1', 'disk.process', ['id' => 'DOC-1'], 'disk.write');
$queue->submit($job);
$leased = $queue->lease('worker-1', ['disk.write']);
if ($leased?->id !== $job->id) throw new RuntimeException('disk_queue_lease_failed');
$free = 1_500;
$queue->complete($job->id, 'worker-1', ['ok' => true]);
if ($queue->get($job->id)?->state !== Job::COMPLETED) throw new RuntimeException('disk_essential_completion_blocked');
$throws(static fn() => $queue->submit(new Job('disk-job-2', 'disk.process', [], 'disk.write')), 'disk_pressure_write_rejected');

$free = 1_500;
$dataCore = new DataCoreTurbo($root . '/datacore', 100, $guard);
$dataCore->insert('documents', ['id' => 'DOC-1', 'value' => 'retained']);
$throws(static fn() => $dataCore->flush(), 'disk_pressure_write_rejected');
$free = 8_000;
$dataCore->flush();
if (($dataCore->find('documents', 'DOC-1')['value'] ?? null) !== 'retained') throw new RuntimeException('disk_datacore_buffer_lost');

$free = 400;
if ($guard->report()['level'] !== DiskPressureGuard::EMERGENCY) throw new RuntimeException('disk_emergency_alert_failed');
$throws(static fn() => $guard->assertWritable('queue.complete', 1, true), 'disk_pressure_emergency');
$free = 8_000;
if ($guard->report()['level'] !== DiskPressureGuard::NORMAL || end($alerts)['current'] !== DiskPressureGuard::NORMAL) {
    throw new RuntimeException('disk_recovery_alert_failed');
}

$invalid = new DiskPressureGuard('/virtual', 4_000, 2_000, 500, static fn(string $path): array => ['total' => 100, 'free' => 101]);
$throws(static fn() => $invalid->report(), 'disk_pressure_probe_invalid');
$throws(static fn() => new DiskPressureGuard('/virtual', 500, 2_000, 100), 'disk_pressure_configuration_invalid');

echo "JAS DISK PRESSURE: PASS\n";
