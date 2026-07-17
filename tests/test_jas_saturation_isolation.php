<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Queue\Job;
use Jah\JAS\Queue\PersistentJobQueue;
use Jah\JAS\Queue\QueueIsolationPolicy;

$root = sys_get_temp_dir() . '/jas_isolation_' . bin2hex(random_bytes(6));
$policy = new QueueIsolationPolicy(2, 1, [
    'social' => ['max_active' => 3, 'max_leased' => 1],
    'payments' => ['max_active' => 3, 'max_leased' => 2],
]);
$queue = new PersistentJobQueue($root . '/queue', 12, 30, null, $policy);

for ($index = 1; $index <= 3; $index++) {
    $queue->submit(Job::create('social.feed.rebuild', ['index' => $index], 'social.feed.write', priority: 100));
}
try {
    $queue->submit(Job::create('social.feed.rebuild', ['index' => 4], 'social.feed.write', priority: 100));
    throw new RuntimeException('isolation_partition_overflow_accepted');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'queue_partition_full:social') throw $error;
}

$payment = $queue->submit(Job::create('payments.settle', ['id' => 'PAY-1'], 'payments.settle', priority: 1));
$socialLease = $queue->lease('social-worker-1', ['social.*']);
if ($socialLease === null || !str_starts_with($socialLease->action, 'social.')) throw new RuntimeException('isolation_social_lease_failed');
if ($queue->lease('social-worker-2', ['social.*']) !== null) throw new RuntimeException('isolation_social_lease_limit_ignored');

$paymentLease = $queue->lease('payments-worker-1', ['payments.*']);
if ($paymentLease?->id !== $payment->id) throw new RuntimeException('isolation_other_domain_blocked');
$queue->complete($payment->id, 'payments-worker-1', ['settled' => true]);
if ($queue->get($payment->id)?->state !== Job::COMPLETED) throw new RuntimeException('isolation_other_domain_not_completed');

$secondPayment = $queue->submit(Job::create('payments.notify', ['id' => 'PAY-2'], 'payments.notify'));
$secondPaymentLease = $queue->lease('payments-worker-2', ['payments.*']);
if ($secondPaymentLease?->id !== $secondPayment->id) throw new RuntimeException('isolation_recovery_domain_failed');
$queue->complete($secondPayment->id, 'payments-worker-2', ['notified' => true]);

$stats = $queue->stats();
if (($stats['partitions']['social']['active'] ?? null) !== 3
    || ($stats['partitions']['social']['saturated'] ?? null) !== true
    || ($stats['partitions']['payments']['active'] ?? null) !== 0) {
    throw new RuntimeException('isolation_partition_stats_invalid');
}

$queue->fail($socialLease->id, 'social-worker-1', 'consumer_saturated', true);
$nextSocial = $queue->lease('social-worker-1', ['social.*']);
if ($nextSocial === null) throw new RuntimeException('isolation_social_retry_lost');
$thirdPayment = $queue->submit(Job::create('payments.audit', ['id' => 'PAY-3'], 'payments.audit'));
if ($thirdPayment->state !== Job::QUEUED) throw new RuntimeException('isolation_failure_spread_between_domains');

try {
    new QueueIsolationPolicy(1, 2);
    throw new RuntimeException('isolation_invalid_policy_accepted');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'queue_isolation_default_invalid') throw $error;
}

$defaultQueue = new PersistentJobQueue($root . '/default-queue', 100);
for ($index = 0; $index < 80; $index++) {
    $defaultQueue->submit(Job::create('abusive.consume', ['index' => $index], 'abusive.consume'));
}
try {
    $defaultQueue->submit(Job::create('abusive.consume', ['index' => 81], 'abusive.consume'));
    throw new RuntimeException('isolation_default_reserve_missing');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'queue_partition_full:abusive') throw $error;
}
$reserved = $defaultQueue->submit(Job::create('critical.respond', [], 'critical.respond'));
if ($reserved->state !== Job::QUEUED) throw new RuntimeException('isolation_default_reserve_unusable');

echo "JAS SATURATION ISOLATION: PASS\n";
