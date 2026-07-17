<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Tooling\ProjectScaffolder;
use Jah\JAS\Tooling\ProjectAnalyzer;
use Jah\JAS\Tooling\ApplicationInspector;

$base = sys_get_temp_dir() . '/jas_scaffold_' . bin2hex(random_bytes(5));
$tool = new ProjectScaffolder();
$files = $tool->create($base, 'Portal Gubernamental');
if (count($files) !== 8 || !is_file($base . '/app/application.php')) throw new RuntimeException('project_scaffold_failed');
$tool->domain($base, 'Tramites', 'tramite');
$tool->type($base, 'NuevoTramite');
$event = $tool->event($base, 'Tramites', 'tramite.creado', 'NuevoTramite');
$action = $tool->action($base, 'Tramites', 'tramite.crear');
foreach ([$base . '/app/Domains/Tramites.php', $base . '/app/Types/NuevoTramite.php', $event, $action] as $file) {
    $loaded = require $file;
    if (!is_array($loaded)) throw new RuntimeException('generated_php_invalid:' . $file);
}
$generatedApplication = require $base . '/app/application.php';
$generatedApplication->validate();
$generatedManifest = $generatedApplication->describe();
if (!isset($generatedManifest['contracts']['tramite.crear']) || !isset($generatedManifest['events']['tramite.creado@1'])) {
    throw new RuntimeException('generated_definitions_not_connected');
}
try { $tool->type($base, 'NuevoTramite'); throw new RuntimeException('generator_overwrote_file'); }
catch (RuntimeException $e) { if ($e->getMessage() !== 'scaffold_file_exists') throw $e; }
$analyzer = new ProjectAnalyzer();
if (!$analyzer->analyze($base)['ok']) throw new RuntimeException('generated_project_analysis_failed');
$inspector = new ApplicationInspector();
$application = $inspector->load($base . '/app/application.php');
$documentation = $inspector->markdown($application);
if (!str_contains($documentation, '# Portal Gubernamental') || !str_contains($documentation, 'Fingerprint:')) throw new RuntimeException('application_documentation_failed');
$unsafeMarker = $base . '/runtime/unsafe-definition-executed';
$unsafe = "<?php\n\n\$value = json_decode('unsafe', true);\nexec('danger');\ntouch(" . var_export($unsafeMarker, true) . ");\n";
file_put_contents($base . '/app/Actions/Unsafe.php', $unsafe);
$analysis = $analyzer->analyze($base);
$codes = array_column($analysis['diagnostics'], 'code');
if ($analysis['ok'] || !in_array('JAS003', $codes, true) || !in_array('JAS010', $codes, true) || !in_array('JAS011', $codes, true)) throw new RuntimeException('project_analyzer_missed_diagnostics');
try {
    $inspector->load($base . '/app/application.php');
    throw new RuntimeException('unsafe_definition_was_loaded');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'generated_definition_prefix_invalid') throw $error;
}
if (is_file($unsafeMarker)) throw new RuntimeException('unsafe_definition_executed');
$maliciousDefinition = "<?php\n\ndeclare(strict_types=1);\n\nreturn ['domain' => 'Tramites', 'name' => 'tramite.malicioso', 'input' => touch(" . var_export($unsafeMarker, true) . "), 'output' => 'NuevoTramite', 'capability' => 'tramite.malicioso'];\n";
file_put_contents($base . '/app/Actions/Unsafe.php', $maliciousDefinition);
try {
    $inspector->load($base . '/app/application.php');
    throw new RuntimeException('executable_literal_was_loaded');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'generated_definition_value_invalid') throw $error;
}
if (is_file($unsafeMarker)) throw new RuntimeException('executable_literal_was_executed');
echo "JAS TOOLING: PASS\n";
