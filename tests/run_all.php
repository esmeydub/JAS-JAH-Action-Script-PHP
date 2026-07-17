<?php

declare(strict_types=1);

// Evita que la salida del coordinador cierre las cabeceras de los procesos
// bifurcados que ejercitan el ciclo de sesión HTTP.
ob_start();

if (!extension_loaded('pcntl')) {
    fwrite(STDERR, "JAS test runner requires PCNTL for process isolation\n");
    exit(2);
}

$root = dirname(__DIR__);
$tests = [
    $root . '/tests/test_jas_core.php',
    $root . '/tests/test_jas_pure_php.php',
    $root . '/tests/test_jas_definition.php',
    $root . '/tests/test_jas_web.php',
    $root . '/tests/test_jas_components.php',
    $root . '/tests/test_jas_forms.php',
    $root . '/tests/test_jas_i18n.php',
    $root . '/tests/test_jas_accessibility.php',
    $root . '/tests/test_jas_upload.php',
    $root . '/tests/test_datacore_database.php',
    $root . '/tests/test_datacore_backup.php',
    $root . '/tests/test_jas_tooling.php',
    $root . '/tests/test_jas_language_engine.php',
    $root . '/tests/test_jas_project_lifecycle.php',
    $root . '/tests/test_jas_fuzz.php',
    $root . '/tests/test_jas_integrated.php',
    $root . '/tests/test_jas_queue.php',
    $root . '/tests/test_jas_dead_letter.php',
    $root . '/tests/test_jas_health.php',
    $root . '/tests/test_jas_operational_panel.php',
    $root . '/tests/test_jas_disk_pressure.php',
    $root . '/tests/test_jas_retention.php',
    $root . '/tests/test_jas_telemetry_export.php',
    $root . '/tests/test_jas_saturation_isolation.php',
    $root . '/tests/test_jas_cluster.php',
    $root . '/tests/test_jas_enterprise.php',
    $root . '/tests/test_jas_security.php',
    $root . '/tests/test_jas_identity.php',
    $root . '/tests/test_jas_regressions.php',
    $root . '/tests/run.php',
    $root . '/php_actionscript_php_doc/test_compiler.php',
];
foreach ($tests as $test) {
    echo "\n== " . basename($test) . " ==\n";
    $pid = pcntl_fork();
    if ($pid === -1) { fwrite(STDERR, "FAIL: fork\n"); exit(2); }
    if ($pid === 0) {
        // Mantiene disponibles las cabeceras durante pruebas HTTP que simulan
        // varias peticiones en un mismo proceso CLI.
        ob_clean();
        try { require $test; exit(0); }
        catch (Throwable $error) { fwrite(STDERR, $error::class . ': ' . $error->getMessage() . "\n"); exit(1); }
    }
    pcntl_waitpid($pid, $status);
    $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
    if ($code !== 0) { fwrite(STDERR, "FAIL: {$test}\n"); exit($code); }
}
echo "\nJAS SUITE: PASS\n";
