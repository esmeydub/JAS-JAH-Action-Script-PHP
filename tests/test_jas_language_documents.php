<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/support.php';

use Jah\JAS\Tooling\DocumentStore;
use Jah\JAS\Tooling\JasLanguageEngine;
use Jah\JAS\Tooling\LanguagePositionCodec;
use Jah\JAS\Tooling\ProjectScaffolder;

$root = sys_get_temp_dir() . '/jas_language_documents_' . bin2hex(random_bytes(6));
$scaffolder = new ProjectScaffolder();
$scaffolder->create($root, 'Documentos LSP');
$scaffolder->domain($root, 'Tramites', 'tramite');
$typeFile = $scaffolder->type($root, 'NuevoTramite');
$actionFile = $scaffolder->action($root, 'Tramites', 'tramite.crear', 'NuevoTramite', 'NuevoTramite', 'tramites.create');

$uri = static fn(string $path): string => 'file://' . str_replace('%2F', '/', rawurlencode($path));
$store = new DocumentStore($root, 8, 1_048_576, 2_097_152, 20_000);
$typeDisk = (string) file_get_contents($typeFile);
$actionDisk = (string) file_get_contents($actionFile);
$typeOpen = str_replace('NuevoTramite', 'TramiteTemporal', $typeDisk);
$actionOpen = str_replace('NuevoTramite', 'TramiteTemporal', $actionDisk);
$typeDocument = $store->open($uri($typeFile), 1, $typeOpen);
$store->open($uri($actionFile), 1, $actionOpen);
if ($typeDocument['hash'] !== hash('sha256', $typeOpen) || $store->count() !== 2
    || $store->totalBytes() !== strlen($typeOpen) + strlen($actionOpen)) {
    throw new RuntimeException('language_document_open_failed');
}

$engine = new JasLanguageEngine(documents: $store);
$offset = strpos($actionOpen, "'input' => 'TramiteTemporal'");
if ($offset === false) throw new RuntimeException('language_document_test_symbol_missing');
$symbolOffset = $offset + strlen("'input' => '");
$before = substr($actionOpen, 0, $symbolOffset);
$line = substr_count($before, "\n") + 1;
$newline = strrpos($before, "\n");
$column = $newline === false ? $symbolOffset + 1 : $symbolOffset - $newline;
$hover = $engine->hover($root, 'app/Actions/TramiteCrear.php', $line, $column);
$definition = $engine->definition($root, 'app/Actions/TramiteCrear.php', $line, $column);
if (($hover['name'] ?? null) !== 'TramiteTemporal'
    || ($definition['file'] ?? null) !== 'app/Types/NuevoTramite.php'
    || str_contains((string) file_get_contents($typeFile), 'TramiteTemporal')) {
    throw new RuntimeException('language_open_content_not_authoritative');
}

$newFile = $root . '/app/Types/SinGuardar.php';
$newContent = "<?php\n\ndeclare(strict_types=1);\n\nreturn ['name' => 'SinGuardar', 'fields' => ['id' => 'identifier'], 'strict' => true];\n";
$store->open($uri($newFile), 1, $newContent);
$newOffset = strpos($newContent, "'SinGuardar'");
$newBefore = substr($newContent, 0, (int) $newOffset + 1);
$newLine = substr_count($newBefore, "\n") + 1;
$newNewline = strrpos($newBefore, "\n");
$newColumn = $newNewline === false ? (int) $newOffset + 2 : (int) $newOffset + 1 - $newNewline;
$newHover = $engine->hover($root, 'app/Types/SinGuardar.php', $newLine, $newColumn);
if (($newHover['name'] ?? null) !== 'SinGuardar' || is_file($newFile)) throw new RuntimeException('language_unsaved_new_file_failed');

$invalid = "<?php\ndeclare(strict_types=1);\nreturn ['name' => ;\n";
$changed = $store->change($uri($actionFile), 2, $invalid);
if ($changed['version'] !== 2 || $store->totalBytes() !== strlen($typeOpen) + strlen($invalid) + strlen($newContent)) {
    throw new RuntimeException('language_document_change_accounting_failed');
}
$diagnostics = $engine->diagnostics($root);
$found = false;
foreach ($diagnostics['diagnostics'] as $diagnostic) {
    if ($diagnostic['code'] === 'JASL001' && $diagnostic['file'] === 'app/Actions/TramiteCrear.php') $found = true;
}
if (!$found) throw new RuntimeException('language_unsaved_diagnostic_missing');

$reject = static function (callable $operation, string $expected): void {
    try { $operation(); } catch (RuntimeException $error) {
        if ($error->getMessage() === $expected) return;
        throw $error;
    }
    throw new RuntimeException('expected_' . $expected);
};
$reject(fn() => $store->change($uri($actionFile), 2, $actionOpen), 'language_document_version_stale');
$reject(fn() => $store->open('file:///etc/passwd', 1, 'x'), 'language_document_outside_workspace');
$reject(fn() => $store->open('https://example.invalid/file.php', 1, 'x'), 'language_document_uri_invalid');
$outside = $root . '-outside.php';
file_put_contents($outside, 'outside');
$link = $root . '/app/Types/Outside.php';
symlink($outside, $link);
$reject(fn() => $store->open($uri($link), 1, 'x'), 'language_document_outside_workspace');

$positions = new LanguagePositionCodec();
$unicode = "a😀é\nx\r\ny";
if ($positions->offset($unicode, 0, 5, 'utf-8') !== 5
    || $positions->offset($unicode, 0, 3, 'utf-16') !== 5
    || $positions->offset($unicode, 0, 2, 'utf-32') !== 5
    || $positions->position($unicode, 5, 'utf-16') !== ['line' => 0, 'character' => 3]
    || $positions->offset($unicode, 1, 1, 'utf-16') !== 9) {
    throw new RuntimeException('language_unicode_position_failed');
}
$reject(fn() => $positions->offset($unicode, 0, 2, 'utf-16'), 'language_position_splits_character');
$reject(fn() => $positions->position($unicode, 10, 'utf-16'), 'language_offset_inside_line_ending');
$reject(fn() => $positions->offset("bad\xFF", 0, 0, 'utf-16'), 'language_position_invalid');

$store->close($uri($actionFile));
if ($store->document($uri($actionFile)) !== null || !str_contains((string) file_get_contents($actionFile), 'NuevoTramite')) {
    throw new RuntimeException('language_document_close_failed');
}
$store->clear();
if ($store->count() !== 0 || $store->totalBytes() !== 0) throw new RuntimeException('language_document_session_cleanup_failed');

unlink($link);
unlink($outside);
jas_test_remove_tree($root);
echo "JAS LANGUAGE DOCUMENTS AND UNICODE: PASS\n";
