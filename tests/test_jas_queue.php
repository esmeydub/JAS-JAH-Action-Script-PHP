<?php

declare(strict_types=1);

$boot = require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\DataCore\PhpSerializer;
use Jah\JAS\Dispatch\JobDispatcher;
use Jah\JAS\ObjectGraph\ObjectRuntime;
use Jah\JAS\Persistence\WalJournal;
use Jah\JAS\Protocol\JasBinaryCodec;
use Jah\JAS\Protocol\JasPacket;
use Jah\JAS\Protocol\Opcodes;
use Jah\JAS\Queue\Job;
use Jah\JAS\Queue\JobService;
use Jah\JAS\Queue\PersistentJobQueue;
use Jah\JAS\Runtime\BinaryRuntime;
use Jah\JAS\Runtime\JasRuntime;
use Jah\JAS\Security\CapabilityPolicy;
use Jah\JAS\Security\ReplayGuard;
use Jah\JAS\Security\SalkPacketGuard;
use Jah\JAS\Security\SalkRuntimeGuard;
use Jah\JAS\Telemetry\MetricsRegistry;
use Jah\JAS\Worker\WorkerDescriptor;
use Jah\JAS\Worker\WorkerRegistry;
use Jah\JAS\Worker\WorkerLoop;

$assert = static function(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function(callable $operation, string $expected): void {
    try { $operation(); } catch (Throwable $e) {
        if ($e->getMessage() === $expected) return;
        throw $e;
    }
    throw new RuntimeException("Expected {$expected}");
};

$base = sys_get_temp_dir() . '/jas_queue_' . bin2hex(random_bytes(6));
mkdir($base, 0700, true);

$queue = new PersistentJobQueue($base . '/queue', 3, 1);
$metrics = new MetricsRegistry($base . '/metrics');
$service = new JobService($queue, $metrics);
$assertThrows(fn() => $queue->lease('', ['*']), 'worker_id_invalid');
$assertThrows(fn() => $queue->lease('worker', ['*'], 0), 'lease_seconds_invalid');
$assertThrows(fn() => $queue->lease('worker', []), 'worker_capabilities_required');

$first = $service->submit([
    'action'=>'math.add',
    'payload'=>['a'=>2,'b'=>3],
    'capability'=>'compute.math',
    'priority'=>5,
    'deduplication_key'=>'sum-2-3',
]);
$second = $service->submit([
    'action'=>'math.add',
    'payload'=>['a'=>2,'b'=>3],
    'capability'=>'compute.math',
    'deduplication_key'=>'sum-2-3',
]);
$assert(($first['job']['id'] ?? null) === ($second['job']['id'] ?? null), 'Deduplicación de cola falló');

$workers = new WorkerRegistry();
$workers->register(new WorkerDescriptor('php-1', 'php', ['compute.*'], 2));
$dispatcher = (new JobDispatcher($queue, $workers, $metrics))
    ->registerExecutor('php', static function(Job $job): array {
        return ['success'=>true,'result'=>(int)$job->payload['a'] + (int)$job->payload['b']];
    });
$result = $dispatcher->dispatchOne();
$assert(($result['success'] ?? false) === true && ($result['result'] ?? null) === 5, 'Despacho local falló');
$stored = $queue->get((string)$first['job']['id']);
$assert($stored?->state === Job::COMPLETED, 'Trabajo no quedó completado');

$retry = $queue->submit(Job::create('unstable', [], 'compute.math', maxAttempts: 2));
$lease = $queue->lease('php-1', ['compute.*'], 1);
$assert($lease?->id === $retry->id, 'Lease no entregó trabajo esperado');
usleep(1_100_000);
$assert($queue->recoverExpired() === 1, 'No recuperó lease vencido');
$assert($queue->get($retry->id)?->state === Job::QUEUED, 'Trabajo vencido no regresó a cola');

$queue->submit(Job::create('job-2', [], 'compute.math'));
$queue->submit(Job::create('job-3', [], 'compute.math'));
$full = false;
try { $queue->submit(Job::create('job-4', [], 'compute.math')); }
catch (RuntimeException $e) { $full = $e->getMessage() === 'queue_full'; }
$assert($full, 'Backpressure queue_full no se aplicó');

$policy = new CapabilityPolicy([
    'jas.local'=>['action.*'],
    'jas.native'=>['queue.job.submit','queue.job.read','queue.job.cancel','queue.stats.read'],
]);
$runtime = new JasRuntime($policy, new WalJournal($base . '/wal'), 'jas.local');
$walValidation = new WalJournal($base . '/wal-validation');
$assertThrows(fn() => $walValidation->begin('', 'action.run', []), 'wal_transaction_id_invalid');
$assertThrows(fn() => $walValidation->begin('tx-valid', '', []), 'wal_operation_invalid');
$walValidation->begin('tx-pending', 'action.run', ['value' => 1]);
$assert(isset($walValidation->pending()['tx-pending']), 'WAL no conservó transacción pendiente');
$walValidation->commit('tx-pending');
$assert($walValidation->pending() === [], 'WAL no cerró transacción confirmada');
$objectRuntime = new ObjectRuntime($runtime->scheduler());
$key = str_repeat('Q', 32);
$codec = new JasBinaryCodec(new SalkPacketGuard($key));
$binary = new BinaryRuntime(
    $codec,
    new SalkRuntimeGuard(new ReplayGuard($base . '/replay', 60), $policy),
    $runtime,
    $objectRuntime,
    'jas.native',
    $service
);
$request = new JasPacket(
    Opcodes::QUEUE_STATS,
    0,
    'req-' . bin2hex(random_bytes(5)),
    'queue',
    PhpSerializer::encode([]),
    time()
);
$response = PhpSerializer::decode($codec->decode($binary->handle($codec->encode($request)))->payload);
$assert(is_array($response) && ($response['success'] ?? false) === true, 'Protocolo binario no expuso estado de cola');

$loopQueue = new PersistentJobQueue($base . '/loop-queue', 10, 1);
$loopJob = $loopQueue->submit(Job::create('loop.echo', ['value'=>9], 'compute.math'));
$loop = new WorkerLoop(
    new WorkerDescriptor('php-loop', 'php', ['compute.*']),
    $loopQueue,
    static fn(Job $job): array => ['success'=>true,'result'=>$job->payload['value'] ?? null],
    $metrics
);
$assert($loop->run(1) === 1, 'WorkerLoop no procesó trabajo');
$assert($loopQueue->get($loopJob->id)?->state === Job::COMPLETED, 'WorkerLoop no completó trabajo');
$loopQueue->compact(true);
$assert($loopQueue->get($loopJob->id)?->state === Job::COMPLETED, 'Compactación perdió estado terminal');

$snapshot = $metrics->snapshot();
$assert(($snapshot['counters']['jobs.submitted'] ?? 0) >= 1, 'Telemetría no registró envíos');
$assert(($snapshot['counters']['jobs.completed'] ?? 0) >= 1, 'Telemetría no registró completados');

echo "JAS QUEUE: PASS\n";
