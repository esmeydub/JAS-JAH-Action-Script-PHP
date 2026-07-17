<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Tooling\GeneratedApplicationLoader;
use Jah\JAS\Tooling\JasLanguageEngine;
use Jah\JAS\Tooling\ProjectScaffolder;

/** @return array{int,int} */
function jas_language_position(string $file, string $prefix): array
{
    $source = file_get_contents($file);
    if (!is_string($source)) throw new RuntimeException('language_test_read_failed');
    $start = strpos($source, $prefix);
    if ($start === false) throw new RuntimeException('language_test_token_missing');
    $offset = $start + strlen($prefix);
    $before = substr($source, 0, $offset);
    $line = substr_count($before, "\n") + 1;
    $newline = strrpos($before, "\n");
    return [$line, $newline === false ? $offset + 1 : $offset - $newline];
}

$root = sys_get_temp_dir() . '/jas_language_' . bin2hex(random_bytes(6));
$scaffolder = new ProjectScaffolder();
$scaffolder->create($root, 'Portal Institucional');
$scaffolder->domain($root, 'Tramites', 'tramite');
$type = $scaffolder->type($root, 'NuevoTramite');
$action = $scaffolder->action($root, 'Tramites', 'tramite.crear', 'NuevoTramite', 'NuevoTramite', 'tramites.create');
$event = $scaffolder->event($root, 'Tramites', 'tramite.creado', 'NuevoTramite');
$engine = new JasLanguageEngine();

[$line, $column] = jas_language_position($action, "'input' => '");
$hover = $engine->hover($root, 'app/Actions/TramiteCrear.php', $line, $column);
if (($hover['kind'] ?? null) !== 'type' || ($hover['name'] ?? null) !== 'NuevoTramite'
    || !str_contains((string) ($hover['detail'] ?? ''), 'Tipo JAS')) throw new RuntimeException('language_hover_failed');
$definition = $engine->definition($root, 'app/Actions/TramiteCrear.php', $line, $column);
if (($definition['file'] ?? null) !== 'app/Types/NuevoTramite.php' || ($definition['role'] ?? null) !== 'declaration') {
    throw new RuntimeException('language_definition_failed');
}
$references = $engine->references($root, 'app/Actions/TramiteCrear.php', $line, $column);
if (count($references) !== 4) throw new RuntimeException('language_references_failed');

$preview = $engine->rename($root, 'app/Actions/TramiteCrear.php', $line, $column, 'SolicitudTramite');
if ($preview['applied'] || count($preview['changes']) !== 4
    || ($preview['files'][0] ?? null) !== ['from' => 'app/Types/NuevoTramite.php', 'to' => 'app/Types/SolicitudTramite.php']
    || !str_contains((string) file_get_contents($type), 'NuevoTramite')) {
    throw new RuntimeException('language_rename_preview_failed');
}
$oldType = $type;
$applied = $engine->rename($root, 'app/Actions/TramiteCrear.php', $line, $column, 'SolicitudTramite', true);
if (!$applied['applied']) throw new RuntimeException('language_rename_apply_failed');
$type = $root . '/app/Types/SolicitudTramite.php';
if (is_file($oldType) || !is_file($type)) throw new RuntimeException('language_type_file_rename_failed');
foreach ([$type, $action, $event] as $file) {
    $source = file_get_contents($file);
    if (!is_string($source) || str_contains($source, "'NuevoTramite'") || !str_contains($source, "'SolicitudTramite'")) {
        throw new RuntimeException('language_rename_reference_lost');
    }
}
(new GeneratedApplicationLoader())->load($root, 'Portal Institucional')->validate();
if (!$engine->diagnostics($root)['ok']) throw new RuntimeException('language_rename_broke_project');

[$capLine, $capColumn] = jas_language_position($action, "'capability' => '");
$capability = $engine->hover($root, 'app/Actions/TramiteCrear.php', $capLine, $capColumn);
if (($capability['kind'] ?? null) !== 'capability' || ($capability['name'] ?? null) !== 'tramites.create') {
    throw new RuntimeException('language_capability_hover_failed');
}
$engine->rename($root, 'app/Actions/TramiteCrear.php', $capLine, $capColumn, 'tramites.submit', true);
if (!str_contains((string) file_get_contents($action), "'tramites.submit'")) throw new RuntimeException('language_capability_rename_failed');

[$actionLine, $actionColumn] = jas_language_position($action, "'name' => '");
$actionHover = $engine->hover($root, 'app/Actions/TramiteCrear.php', $actionLine, $actionColumn);
if (($actionHover['kind'] ?? null) !== 'action' || ($actionHover['name'] ?? null) !== 'tramite.crear') {
    throw new RuntimeException('language_action_hover_failed');
}
$engine->rename($root, 'app/Actions/TramiteCrear.php', $actionLine, $actionColumn, 'tramite.registrar', true);
$oldAction = $action;
$action = $root . '/app/Actions/TramiteRegistrar.php';
if (is_file($oldAction) || !is_file($action)) throw new RuntimeException('language_action_file_rename_failed');
if (!str_contains((string) file_get_contents($action), "'tramite.registrar'")) throw new RuntimeException('language_action_rename_failed');

[$eventLine, $eventColumn] = jas_language_position($event, "'name' => '");
$eventHover = $engine->hover($root, 'app/Events/TramiteCreadoV1.php', $eventLine, $eventColumn);
if (($eventHover['kind'] ?? null) !== 'event' || ($eventHover['name'] ?? null) !== 'tramite.creado') {
    throw new RuntimeException('language_event_hover_failed');
}
$engine->rename($root, 'app/Events/TramiteCreadoV1.php', $eventLine, $eventColumn, 'tramite.registrado', true);
$oldEvent = $event;
$event = $root . '/app/Events/TramiteRegistradoV1.php';
if (is_file($oldEvent) || !is_file($event)) throw new RuntimeException('language_event_file_rename_failed');
if (!str_contains((string) file_get_contents($event), "'tramite.registrado'")) throw new RuntimeException('language_event_rename_failed');

$domain = $root . '/app/Domains/Tramites.php';
[$domainLine, $domainColumn] = jas_language_position($domain, "'name' => '");
$domainReferences = $engine->references($root, 'app/Domains/Tramites.php', $domainLine, $domainColumn);
if (count($domainReferences) !== 3) throw new RuntimeException('language_domain_references_failed');
$engine->rename($root, 'app/Domains/Tramites.php', $domainLine, $domainColumn, 'Servicios', true);
$oldDomain = $domain;
$domain = $root . '/app/Domains/Servicios.php';
if (is_file($oldDomain) || !is_file($domain)) throw new RuntimeException('language_domain_file_rename_failed');
foreach ([$domain, $action, $event] as $file) {
    if (!str_contains((string) file_get_contents($file), "'Servicios'")) throw new RuntimeException('language_domain_rename_failed');
}

[$actionLine, $actionColumn] = jas_language_position($action, "'name' => '");
try {
    $engine->rename($root, 'app/Actions/TramiteRegistrar.php', $actionLine, $actionColumn, '../unsafe', true);
    throw new RuntimeException('language_invalid_rename_accepted');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'language_rename_invalid') throw $error;
}
try {
    $engine->hover($root, '../outside.php', 1, 1);
    throw new RuntimeException('language_path_escape_accepted');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'language_position_invalid') throw $error;
}

$otherType = $scaffolder->type($root, 'TipoAlterno');
[$renamedTypeLine, $renamedTypeColumn] = jas_language_position($type, "'name' => '");
try {
    $engine->rename($root, 'app/Types/SolicitudTramite.php', $renamedTypeLine, $renamedTypeColumn, 'TipoAlterno', true);
    throw new RuntimeException('language_symbol_collision_accepted');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'language_symbol_conflict') throw $error;
}
if (!str_contains((string) file_get_contents($otherType), "'TipoAlterno'")) throw new RuntimeException('language_collision_modified_destination');

$blockedTarget = $root . '/app/Types/Bloqueado.php';
file_put_contents($blockedTarget, "<?php\n\ndeclare(strict_types=1);\n\nreturn ['reserved' => true];\n");
$beforeConflict = [];
foreach ([$type, $action, $event] as $file) $beforeConflict[$file] = hash_file('sha256', $file);
try {
    $engine->rename($root, 'app/Types/SolicitudTramite.php', $renamedTypeLine, $renamedTypeColumn, 'Bloqueado', true);
    throw new RuntimeException('language_file_collision_accepted');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'workspace_rename_conflict') throw $error;
}
foreach ($beforeConflict as $file => $hash) {
    if (!is_file($file) || !hash_equals((string) $hash, (string) hash_file('sha256', $file))) {
        throw new RuntimeException('language_file_collision_rollback_failed');
    }
}
if (!is_file($blockedTarget)) throw new RuntimeException('language_file_collision_target_lost');

$marker = $root . '/runtime/language-executed';
file_put_contents($root . '/app/Types/Hostile.php', "<?php touch(" . var_export($marker, true) . "); return ['name' => 'Hostile'];\n");
$engine->diagnostics($root);
$engine->hover($root, 'app/Types/SolicitudTramite.php', 1, 1);
if (is_file($marker)) throw new RuntimeException('language_engine_executed_source');

echo "JAS LANGUAGE INTELLIGENCE: PASS\n";
