<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/DataCore/autoload.php';
require_once dirname(__DIR__) . '/app/memory/TieredMemory.php';

use Jah\Memory\TieredMemory;

$documents = max(100, min((int)($argv[1] ?? 10_000), 100_000));
$base = sys_get_temp_dir() . '/jah_benchmark_' . bin2hex(random_bytes(6));
$dataPath = $base . '/datacore';
$pyramidPath = $base . '/pyramid';

$memory = new TieredMemory($dataPath, $pyramidPath);
$started = hrtime(true);
for ($i = 0; $i < $documents; $i++) {
    $memory->store(
        'doc_' . $i,
        ['content' => "memory payload number {$i} commonword", '_ts' => time()],
        'hot',
        'benchmark'
    );
}
$writeMs = (hrtime(true) - $started) / 1_000_000;
$memory->close();

function benchmarkMedian(callable $operation, int $runs = 9): float
{
    $times = [];
    for ($i = 0; $i < $runs; $i++) {
        $started = hrtime(true);
        $operation();
        $times[] = (hrtime(true) - $started) / 1_000_000;
    }
    sort($times);
    return $times[intdiv(count($times), 2)];
}

$missingMs = benchmarkMedian(function () use ($dataPath, $pyramidPath): void {
    $memory = new TieredMemory($dataPath, $pyramidPath);
    $memory->search('term_that_does_not_exist', 'benchmark', 20);
    $memory->close();
});

$rareMs = benchmarkMedian(function () use ($dataPath, $pyramidPath, $documents): void {
    $memory = new TieredMemory($dataPath, $pyramidPath);
    $memory->search((string)($documents - 1), 'benchmark', 20);
    $memory->close();
});

$commonMs = benchmarkMedian(function () use ($dataPath, $pyramidPath): void {
    $memory = new TieredMemory($dataPath, $pyramidPath);
    $memory->search('commonword', 'benchmark', 20);
    $memory->close();
});

$retrieveMs = benchmarkMedian(function () use ($dataPath, $pyramidPath, $documents): void {
    $memory = new TieredMemory($dataPath, $pyramidPath);
    $memory->retrieve('doc_' . ($documents - 1), null, 'benchmark');
    $memory->close();
});

echo "JAH_DATACORE_BENCHMARK\n";
echo 'documents: ' . $documents . "\n";
echo 'indexed_write_total_ms: ' . round($writeMs, 3) . "\n";
echo 'indexed_write_per_document_ms: ' . round($writeMs / $documents, 4) . "\n";
echo 'search_missing_median_ms: ' . round($missingMs, 3) . "\n";
echo 'search_rare_median_ms: ' . round($rareMs, 3) . "\n";
echo 'search_common_median_ms: ' . round($commonMs, 3) . "\n";
echo 'retrieve_by_id_median_ms: ' . round($retrieveMs, 3) . "\n";
echo 'storage_path: ' . $base . "\n";
