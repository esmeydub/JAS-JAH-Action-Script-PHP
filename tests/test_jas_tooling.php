<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Tooling\ProjectScaffolder;
use Jah\JAS\Tooling\ProjectAnalyzer;
use Jah\JAS\Tooling\ApplicationInspector;
use Jah\JAS\Tooling\DefinitionEditor;
use Jah\JAS\Tooling\JasFormatter;

$base = sys_get_temp_dir() . '/jas_scaffold_' . bin2hex(random_bytes(5));
$phpstanConfig = file_get_contents(dirname(__DIR__) . '/phpstan.neon.dist');
if (!is_string($phpstanConfig) || !str_contains($phpstanConfig, 'level: 5')
    || !str_contains($phpstanConfig, 'src/JAS') || !str_contains($phpstanConfig, 'src/DataCore')) {
    throw new RuntimeException('phpstan_integration_missing');
}
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
$tool->domain($base, 'Identidad', 'identidad');
$tool->type($base, 'TramiteCreado');
$editor = new DefinitionEditor();
$fieldUpdate = $editor->addTypeField($base, 'NuevoTramite', 'descripcion?', 'string');
if (!$fieldUpdate['changed'] || !isset($fieldUpdate['definition']['fields']['descripcion?'])) throw new RuntimeException('type_safe_update_failed');
$dependencyUpdate = $editor->addDomainDependency($base, 'Tramites', 'Identidad');
if (!$dependencyUpdate['changed'] || $dependencyUpdate['definition']['dependencies'] !== ['Identidad']) throw new RuntimeException('domain_safe_update_failed');
try {
    $editor->addDomainDependency($base, 'Identidad', 'Tramites');
    throw new RuntimeException('domain_cycle_was_written');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'definition_domain_dependency_cycle') throw $error;
}
$actionUpdate = $editor->configureAction($base, 'tramite.crear', 'NuevoTramite', 'TramiteCreado', 'tramites.create');
if (!$actionUpdate['changed'] || $actionUpdate['definition']['output'] !== 'TramiteCreado') throw new RuntimeException('action_safe_update_failed');
$updatedApplication = require $base . '/app/application.php';
$updatedApplication->validate();
if (($updatedApplication->describe()['contracts']['tramite.crear']['capability'] ?? null) !== 'tramites.create') throw new RuntimeException('updated_contract_not_loaded');
$compactType = "<?php declare(strict_types=1); return ['name'=>'TramiteCreado','fields'=>['id'=>'identifier'],'strict'=>true];\n";
file_put_contents($base . '/app/Types/TramiteCreado.php', $compactType);
$formatter = new JasFormatter();
$formatCheck = $formatter->format($base, false);
if ($formatCheck['ok'] || !in_array('app/Types/TramiteCreado.php', $formatCheck['changed'], true)) throw new RuntimeException('formatter_check_missed_change');
if (file_get_contents($base . '/app/Types/TramiteCreado.php') !== $compactType) throw new RuntimeException('formatter_check_modified_file');
$formatApply = $formatter->format($base, true);
if ($formatApply['changed'] === [] || !$formatter->format($base, false)['ok']) throw new RuntimeException('formatter_not_idempotent');
try { $tool->type($base, 'NuevoTramite'); throw new RuntimeException('generator_overwrote_file'); }
catch (RuntimeException $e) { if ($e->getMessage() !== 'scaffold_file_exists') throw $e; }
mkdir($base . '/app/Domains/Identidad', 0700);
mkdir($base . '/app/Domains/Tramites', 0700);
file_put_contents($base . '/app/Domains/Identidad/IdentityService.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Domains\Identidad;

final class IdentityService {}
PHP
    . "\n");
file_put_contents($base . '/app/Domains/Tramites/Workflow.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Domains\Tramites;

use App\Domains\Identidad\IdentityService;

final class Workflow
{
    public function __construct(public readonly IdentityService $identities) {}
}
PHP
    . "\n");
$analyzer = new ProjectAnalyzer();
if (!$analyzer->analyze($base)['ok']) throw new RuntimeException('generated_project_analysis_failed');
$inspector = new ApplicationInspector();
$application = $inspector->load($base . '/app/application.php');
$documentation = $inspector->markdown($application);
if (!str_contains($documentation, '# Portal Gubernamental') || !str_contains($documentation, 'Fingerprint:')) throw new RuntimeException('application_documentation_failed');
$tool->domain($base, 'Notificaciones', 'notificacion');
mkdir($base . '/app/Domains/Notificaciones', 0700);
file_put_contents($base . '/app/Domains/Notificaciones/Alert.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Domains\Notificaciones;

use App\Domains\Identidad\IdentityService;
use App\Missing\Ghost;

final class Alert {}
PHP
    . "\n");
file_put_contents($base . '/app/Web/Misplaced.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Web;

final class ExpectedName {}
PHP
    . "\n");
$unsafeMarker = $base . '/runtime/unsafe-definition-executed';
$unsafe = "<?php\n\n\$value = json_decode('unsafe', true);\nexec('danger');\ntouch(" . var_export($unsafeMarker, true) . ");\n";
file_put_contents($base . '/app/Actions/Unsafe.php', $unsafe);
$analysis = $analyzer->analyze($base);
$codes = array_column($analysis['diagnostics'], 'code');
if ($analysis['ok']
    || !in_array('JAS003', $codes, true)
    || !in_array('JAS010', $codes, true)
    || !in_array('JAS011', $codes, true)
    || !in_array('JAS031', $codes, true)
    || !in_array('JAS032', $codes, true)
    || !in_array('JAS040', $codes, true)
    || !in_array('JAS050', $codes, true)) throw new RuntimeException('project_analyzer_missed_diagnostics');
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
