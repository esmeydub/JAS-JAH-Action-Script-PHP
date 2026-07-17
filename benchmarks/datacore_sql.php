<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/tests/support.php';

use Jah\DataCore\DataCoreDatabase;
use Jah\DataCore\DataCoreTurbo;
use Jah\JAS\Type\TypeRegistry;
function processCpuSeconds(): float
{
    $usage = getrusage();
    return ((int) ($usage['ru_utime.tv_sec'] ?? 0))
        + ((int) ($usage['ru_utime.tv_usec'] ?? 0)) / 1_000_000
        + ((int) ($usage['ru_stime.tv_sec'] ?? 0))
        + ((int) ($usage['ru_stime.tv_usec'] ?? 0)) / 1_000_000;
}

/** @return array{seconds:float,cpu_seconds:float,memory_bytes:int,peak_bytes:int} */
function measure(callable $operation): array
{
    gc_collect_cycles();
    $memory = memory_get_usage(true);
    $peak = memory_get_peak_usage(true);
    $cpu = processCpuSeconds();
    $started = hrtime(true);
    $operation();

    return [
        'seconds' => (hrtime(true) - $started) / 1_000_000_000,
        'cpu_seconds' => max(0.0, processCpuSeconds() - $cpu),
        'memory_bytes' => max(0, memory_get_usage(true) - $memory),
        'peak_bytes' => max(0, memory_get_peak_usage(true) - $peak),
    ];
}

function directoryBytes(string $path): int
{
    if (!is_dir($path)) return is_file($path) ? (int) filesize($path) : 0;
    $bytes = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $entry) {
        if ($entry->isFile()) $bytes += $entry->getSize();
    }
    return $bytes;
}

function humanBytes(int $bytes): string
{
    $units = ['B', 'KiB', 'MiB', 'GiB'];
    $value = (float) $bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    return number_format($value, 2, '.', '') . ' ' . $units[$unit];
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "Benchmark omitido: ext-pdo_sqlite no disponible.\n");
    exit(2);
}

$records = isset($argv[1]) ? filter_var($argv[1], FILTER_VALIDATE_INT) : 2_000;
if (!is_int($records) || $records < 100 || $records > 100_000) {
    throw new RuntimeException('benchmark_records_must_be_between_100_and_100000');
}

$base = sys_get_temp_dir() . '/jas_benchmark_' . bin2hex(random_bytes(6));
$datacorePath = $base . '/datacore';
$sqlitePath = $base . '/sql-mirror.sqlite';
$targetEmail = 'persona-' . ($records - 1) . '@ejemplo.gob.mx';

try {
    $types = (new TypeRegistry())->define('BenchmarkPersona', [
        'id' => 'identifier',
        'institucion' => 'identifier',
        'correo' => 'non-empty-string',
        'nombre' => 'non-empty-string',
        'activo' => 'bool',
    ]);
    $datacore = (new DataCoreDatabase(
        new DataCoreTurbo($datacorePath . '/storage', 1),
        $types,
        $datacorePath . '/runtime',
        random_bytes(32),
    ))->collection('personas', 'BenchmarkPersona')
        ->index('personas', 'institucion_correo', ['institucion', 'correo']);

    $datacoreWrite = measure(static function () use ($datacore, $records): void {
        for ($number = 0; $number < $records; $number++) {
            $datacore->insert('personas', [
                'id' => 'PERSONA-' . $number,
                'institucion' => 'GOB-MX',
                'correo' => 'persona-' . $number . '@ejemplo.gob.mx',
                'nombre' => 'Persona de prueba ' . $number,
                'activo' => true,
            ]);
        }
    });
    $datacoreResult = [];
    $datacoreRead = measure(static function () use ($datacore, $targetEmail, &$datacoreResult): void {
        for ($attempt = 0; $attempt < 1_000; $attempt++) {
            $datacoreResult = $datacore->findByIndex('personas', 'institucion_correo', [
                'institucion' => 'GOB-MX',
                'correo' => $targetEmail,
            ], 1);
        }
    });

    $pdo = new PDO('sqlite:' . $sqlitePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA synchronous=FULL');
    $pdo->exec(
        'CREATE TABLE personas ('
        . 'id TEXT PRIMARY KEY, institucion TEXT NOT NULL, correo TEXT NOT NULL, '
        . 'nombre TEXT NOT NULL, activo INTEGER NOT NULL)',
    );
    $pdo->exec('CREATE INDEX personas_institucion_correo ON personas (institucion, correo)');
    $insert = $pdo->prepare(
        'INSERT INTO personas (id, institucion, correo, nombre, activo) VALUES (?, ?, ?, ?, ?)',
    );
    $sqlWrite = measure(static function () use ($pdo, $insert, $records): void {
        $pdo->beginTransaction();
        try {
            for ($number = 0; $number < $records; $number++) {
                $insert->execute([
                    'PERSONA-' . $number,
                    'GOB-MX',
                    'persona-' . $number . '@ejemplo.gob.mx',
                    'Persona de prueba ' . $number,
                    1,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    });
    $select = $pdo->prepare(
        'SELECT id, institucion, correo, nombre, activo FROM personas '
        . 'WHERE institucion = ? AND correo = ? LIMIT 1',
    );
    $sqlResult = false;
    $sqlRead = measure(static function () use ($select, $targetEmail, &$sqlResult): void {
        for ($attempt = 0; $attempt < 1_000; $attempt++) {
            $select->execute(['GOB-MX', $targetEmail]);
            $sqlResult = $select->fetch(PDO::FETCH_ASSOC);
        }
    });

    if (count($datacoreResult) !== 1 || !is_array($sqlResult)) {
        throw new RuntimeException('benchmark_correctness_failed');
    }
    if (($datacoreResult[0]['id'] ?? null) !== ($sqlResult['id'] ?? null)) {
        throw new RuntimeException('benchmark_result_mismatch');
    }

    $results = [
        ['DataCore', $datacoreWrite, $datacoreRead, directoryBytes($datacorePath)],
        ['SQLite', $sqlWrite, $sqlRead, directoryBytes($base) - directoryBytes($datacorePath)],
    ];

    echo "JAS DataCore / SQL reproducible benchmark\n";
    echo 'PHP: ' . PHP_VERSION . ' | OS: ' . PHP_OS_FAMILY . ' | records: ' . $records . "\n";
    echo "Durability: DataCore flush per record; SQLite WAL + FULL, one transaction.\n";
    echo "Read measurement: 1,000 exact indexed queries after warm-up by writes.\n\n";
    printf("%-10s %14s %16s %14s %14s %14s %14s\n", 'Engine', 'write seconds', '1k reads ms', 'CPU seconds', 'memory delta', 'peak delta', 'disk');
    foreach ($results as [$engine, $write, $read, $disk]) {
        printf(
            "%-10s %14.6f %16.6f %14.6f %14s %14s %14s\n",
            $engine,
            $write['seconds'],
            $read['seconds'] * 1_000,
            $write['cpu_seconds'] + $read['cpu_seconds'],
            humanBytes($write['memory_bytes']),
            humanBytes(max($write['peak_bytes'], $read['peak_bytes'])),
            humanBytes($disk),
        );
    }
    echo "\nResultado: PASS; ambos motores devolvieron el mismo identificador.\n";
    echo "Advertencia: esta microprueba local no demuestra superioridad universal ni sustituye carga real.\n";
} finally {
    jas_test_remove_tree($base);
}
