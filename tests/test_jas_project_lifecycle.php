<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Definition\CompatibilityChecker;
use Jah\JAS\Tooling\ApplicationInspector;
use Jah\JAS\Tooling\DefinitionEditor;
use Jah\JAS\Tooling\ProjectScaffolder;

/** @return array{int,string,string} */
function jas_lifecycle_process(array $command, array $environment = []): array
{
    $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptor, $pipes, null, $environment + ['PATH' => (string) getenv('PATH')]);
    if (!is_resource($process)) throw new RuntimeException('lifecycle_process_start_failed');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return [$code, is_string($stdout) ? $stdout : '', is_string($stderr) ? $stderr : ''];
}

function jas_lifecycle_project(string $root, string $capability = 'tramites.create'): void
{
    $tool = new ProjectScaffolder();
    $tool->create($root, 'Portal Institucional');
    $tool->domain($root, 'Tramites', 'tramite');
    $tool->type($root, 'Solicitud');
    $tool->action($root, 'Tramites', 'tramite.crear', 'Solicitud', 'Solicitud', $capability);
    $tool->event($root, 'Tramites', 'tramite.creado', 'Solicitud');
}

$workspace = sys_get_temp_dir() . '/jas_lifecycle_' . bin2hex(random_bytes(6));
$previous = $workspace . '/previous';
$compatible = $workspace . '/compatible';
$breaking = $workspace . '/breaking';
jas_lifecycle_project($previous);
jas_lifecycle_project($compatible);
jas_lifecycle_project($breaking, 'tramites.submit');
(new DefinitionEditor())->addTypeField($compatible, 'Solicitud', 'descripcion?', 'string');

$readme = file_get_contents($previous . '/README.md');
if (!is_string($readme) || !str_contains($readme, 'Inicio único') || !str_contains($readme, 'app:docs')
    || !str_contains($readme, 'No se requieren manifiestos JSON')) throw new RuntimeException('generated_project_guide_incomplete');

[$smokeCode, $smokeOutput, $smokeError] = jas_lifecycle_process(
    [PHP_BINARY, $previous . '/tests/smoke.php'],
    ['JAS_ROOT' => dirname(__DIR__)]
);
if ($smokeCode !== 0 || trim($smokeOutput) !== 'JAS APP: PASS' || $smokeError !== '') {
    throw new RuntimeException('generated_project_not_standalone');
}

$inspector = new ApplicationInspector();
$application = $inspector->project($previous);
$markdown = $inspector->markdown($application);
$mermaid = $inspector->mermaid($application);
if ($markdown !== $inspector->markdown($application) || !str_contains($markdown, '## Diagrama de dominios')
    || !str_contains($markdown, 'Fingerprint:') || !str_contains($mermaid, 'D_Tramites')
    || !str_contains($mermaid, 'tramite.crear')) throw new RuntimeException('application_artifacts_invalid');
$docs = $workspace . '/APPLICATION.md';
$diagrams = $workspace . '/APPLICATION.mmd';
$inspector->writeMarkdown($application, $docs);
$inspector->writeMermaid($application, $diagrams);
if (file_get_contents($docs) !== $markdown || file_get_contents($diagrams) !== $mermaid) throw new RuntimeException('application_artifacts_write_failed');

$compatibleReport = (new CompatibilityChecker())->compare($application->describe(), $inspector->project($compatible)->describe());
if (!$compatibleReport->compatible() || !in_array('optional_type_field_added:Solicitud:descripcion?', $compatibleReport->warnings, true)) {
    throw new RuntimeException('compatible_project_rejected');
}
$breakingReport = (new CompatibilityChecker())->compare($application->describe(), $inspector->project($breaking)->describe());
if ($breakingReport->compatible() || !in_array('action_changed:tramite.crear:capability', $breakingReport->breaking, true)) {
    throw new RuntimeException('breaking_project_accepted');
}

[$compatCode, $compatOutput] = jas_lifecycle_process(
    [PHP_BINARY, dirname(__DIR__) . '/bin/jas', 'app:compat', $previous, $breaking]
);
if ($compatCode !== 1 || !str_contains($compatOutput, 'BREAKING: action_changed:tramite.crear:capability')
    || !str_contains($compatOutput, 'JAS COMPATIBILITY: BREAKING')) throw new RuntimeException('compatibility_cli_failed');

[$docsCode, $docsOutput] = jas_lifecycle_process(
    [PHP_BINARY, dirname(__DIR__) . '/bin/jas', 'app:docs', $previous, $workspace . '/CLI_APPLICATION.md']
);
if ($docsCode !== 0 || !str_contains($docsOutput, 'Documentation created:')
    || !is_file($workspace . '/CLI_APPLICATION.md')) throw new RuntimeException('documentation_cli_failed');

echo "JAS PROJECT LIFECYCLE: PASS\n";
