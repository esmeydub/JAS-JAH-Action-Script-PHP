<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/support.php';

use Jah\JAS\Observability\SustainedOperationProbe;

$cycles = max(25, min(10_000, (int) ($argv[1] ?? 100)));
$root = sys_get_temp_dir() . '/jas_operations_qualification_' . bin2hex(random_bytes(6));
$probe = new SustainedOperationProbe($root . '/runtime/operations/probe', 20);
$started = hrtime(true);
$operations = 0;
$accepted = 0;
$rejected = 0;
$valid = true;

for ($cycle = 0; $cycle < $cycles; $cycle++) {
    $sample = $probe->run();
    $operations += $sample['operations'];
    $accepted += $sample['accepted'];
    $rejected += $sample['rejected'];
    $valid = $valid
        && $sample['operations'] === $sample['accepted'] + $sample['rejected']
        && $sample['integrity_valid']
        && $sample['recovery_valid']
        && $sample['readiness']
        && $sample['queue_bounded'];
}

$seconds = (hrtime(true) - $started) / 1_000_000_000;
$report = [
    'cycles' => $cycles,
    'operations' => $operations,
    'accepted' => $accepted,
    'rejected' => $rejected,
    'integrity_valid' => $valid,
    'seconds' => round($seconds, 6),
    'operations_per_second' => round($operations / max($seconds, 0.000001), 3),
];

echo "JAS OPERATIONS QUALIFICATION\n";
foreach ($report as $name => $value) {
    echo $name . ': ' . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) . "\n";
}
jas_test_remove_tree($root);
if (!$valid || $operations !== $cycles * 21 || $accepted + $rejected !== $operations) {
    throw new RuntimeException('operations_qualification_failed');
}
echo "JAS OPERATIONS QUALIFICATION: PASS\n";
