<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/support.php';

use Jah\JAS\Protocol\JasBinaryCodec;
use Jah\JAS\Protocol\LanguageMessage;
use Jah\JAS\Protocol\LanguageProtocolCodec;
use Jah\JAS\Security\SalkPacketGuard;
use Jah\JAS\Tooling\LanguageBinaryService;
use Jah\JAS\Tooling\LanguagePositionCodec;
use Jah\JAS\Tooling\LanguageStdioServer;
use Jah\JAS\Tooling\ProjectScaffolder;
use Jah\JAS\Transport\FrameProtocol;

$root = sys_get_temp_dir() . '/jas_language_service_' . bin2hex(random_bytes(6));
$scaffolder = new ProjectScaffolder();
$scaffolder->create($root, 'Servicio Binario');
$scaffolder->domain($root, 'Tramites', 'tramite');
$type = $scaffolder->type($root, 'NuevoTramite');
$action = $scaffolder->action($root, 'Tramites', 'tramite.crear', 'NuevoTramite', 'NuevoTramite', 'tramites.create');
$actionSource = (string) file_get_contents($action);
$uri = static fn(string $path): string => 'file://' . str_replace('%2F', '/', rawurlencode($path));
$workspaceUri = $uri($root);
$actionUri = $uri($action);
$protocol = new LanguageProtocolCodec(new JasBinaryCodec(new SalkPacketGuard(str_repeat('S', 32))));
$service = new LanguageBinaryService($protocol, $root);
$sequence = 0;
$packet = static function (LanguageMessage $message, string $session = 'session-l2', ?int $timestamp = null) use ($protocol, &$sequence): string {
    return $protocol->encode($message, 'request-' . ++$sequence, $session, $timestamp ?? time());
};
$decode = static fn(string $binary): LanguageMessage => $protocol->decode($binary)['message'];

$premature = $service->handle($packet(new LanguageMessage('request', 'textDocument/hover', 1, [
    'uri' => $actionUri, 'version' => 0, 'line' => 0, 'character' => 0, 'position_encoding' => 'utf-16',
])));
if (count($premature) !== 1 || $decode($premature[0])->body['code'] !== -32002) throw new RuntimeException('language_service_preinitialize_failed');

$initialize = $service->handle($packet(new LanguageMessage('request', 'initialize', 2, [
    'workspace_uri' => $workspaceUri, 'process_id' => 123,
    'position_encodings' => ['utf-8', 'utf-16'],
])));
$initializedResponse = $decode($initialize[0]);
if ($initializedResponse->kind !== 'response' || $initializedResponse->body['position_encoding'] !== 'utf-8'
    || $initializedResponse->body['text_sync'] !== 'full') throw new RuntimeException('language_service_initialize_failed');
if ($service->handle($packet(new LanguageMessage('notification', 'initialized', null, []))) !== []) {
    throw new RuntimeException('language_service_initialized_response_forbidden');
}

$opened = $service->handle($packet(new LanguageMessage('notification', 'textDocument/didOpen', null, [
    'uri' => $actionUri, 'version' => 1, 'language_id' => 'jas-php', 'content' => $actionSource,
])));
if (count($opened) !== 1 || $decode($opened[0])->method !== 'textDocument/publishDiagnostics') {
    throw new RuntimeException('language_service_open_diagnostics_failed');
}

$symbol = strpos($actionSource, "'input' => 'NuevoTramite'");
if ($symbol === false) throw new RuntimeException('language_service_symbol_missing');
$symbol += strlen("'input' => '");
$lspPosition = (new LanguagePositionCodec())->position($actionSource, $symbol, 'utf-8');
$position = [
    'uri' => $actionUri, 'version' => 1, 'line' => $lspPosition['line'],
    'character' => $lspPosition['character'], 'position_encoding' => 'utf-8',
];
$hover = $decode($service->handle($packet(new LanguageMessage('request', 'textDocument/hover', 'hover-1', $position)))[0]);
if (($hover->body['hover']['name'] ?? null) !== 'NuevoTramite'
    || !isset($hover->body['hover']['location']['range']['start'])) throw new RuntimeException('language_service_hover_failed');
$definition = $decode($service->handle($packet(new LanguageMessage('request', 'textDocument/definition', 3, $position)))[0]);
if (($definition->body['location']['uri'] ?? null) !== $uri($type)) throw new RuntimeException('language_service_definition_failed');
$references = $decode($service->handle($packet(new LanguageMessage('request', 'textDocument/references', 4, $position)))[0]);
if (count($references->body['locations'] ?? []) < 3) throw new RuntimeException('language_service_references_failed');
$prepared = $decode($service->handle($packet(new LanguageMessage('request', 'textDocument/prepareRename', 5, $position)))[0]);
if (($prepared->body['placeholder'] ?? null) !== 'NuevoTramite') throw new RuntimeException('language_service_prepare_rename_failed');
$rename = $decode($service->handle($packet(new LanguageMessage('request', 'textDocument/rename', 6, $position + ['new_name' => 'SolicitudTramite'])))[0]);
if (count($rename->body['changes'] ?? []) < 3 || count($rename->body['file_renames'] ?? []) !== 1
    || !str_contains((string) file_get_contents($type), 'NuevoTramite')) throw new RuntimeException('language_service_workspace_edit_failed');

$invalidSource = "<?php\ndeclare(strict_types=1);\nreturn ['name' => ;\n";
$changed = $service->handle($packet(new LanguageMessage('notification', 'textDocument/didChange', null, [
    'uri' => $actionUri, 'version' => 2, 'changes' => [['text' => $invalidSource]],
])));
$diagnostics = $decode($changed[0]);
if (($diagnostics->body['version'] ?? null) !== 2 || count($diagnostics->body['diagnostics'] ?? []) < 1) {
    throw new RuntimeException('language_service_unsaved_diagnostics_failed');
}
$stale = $service->handle($packet(new LanguageMessage('notification', 'textDocument/didChange', null, [
    'uri' => $actionUri, 'version' => 2, 'changes' => [['text' => $actionSource]],
])));
if ($stale !== []) throw new RuntimeException('language_service_notification_error_response');

$closed = $service->handle($packet(new LanguageMessage('notification', 'textDocument/didClose', null, ['uri' => $actionUri])));
if (($decode($closed[0])->body['version'] ?? null) !== 2 || $decode($closed[0])->body['diagnostics'] !== []) {
    throw new RuntimeException('language_service_close_failed');
}
$shutdown = $service->handle($packet(new LanguageMessage('request', 'shutdown', 7, [])));
if ($decode($shutdown[0])->kind !== 'response') throw new RuntimeException('language_service_shutdown_failed');
$afterShutdown = $service->handle($packet(new LanguageMessage('request', 'textDocument/hover', 8, $position)));
if ($decode($afterShutdown[0])->kind !== 'error') throw new RuntimeException('language_service_shutdown_boundary_failed');
$service->handle($packet(new LanguageMessage('notification', 'exit', null, [])));
if (!$service->exited()) throw new RuntimeException('language_service_exit_failed');

$replayService = new LanguageBinaryService($protocol, $root);
$replayed = $packet(new LanguageMessage('request', 'initialize', 9, [
    'workspace_uri' => $workspaceUri, 'process_id' => null, 'position_encodings' => ['utf-16'],
]), 'replay-session');
$replayService->handle($replayed);
try { $replayService->handle($replayed); throw new RuntimeException('language_service_replay_accepted'); }
catch (RuntimeException $error) { if ($error->getMessage() !== 'language_message_replay') throw $error; }
$expiredService = new LanguageBinaryService($protocol, $root);
try {
    $expiredService->handle($packet(new LanguageMessage('request', 'initialize', 10, [
        'workspace_uri' => $workspaceUri, 'process_id' => null, 'position_encodings' => ['utf-16'],
    ]), 'expired-session', time() - 61));
    throw new RuntimeException('language_service_expired_accepted');
} catch (RuntimeException $error) { if ($error->getMessage() !== 'language_message_expired') throw $error; }

$stdioService = new LanguageBinaryService($protocol, $root);
$server = new LanguageStdioServer($stdioService);
$frames = new FrameProtocol(8_389_256);
$input = fopen('php://temp', 'w+b');
$output = fopen('php://temp', 'w+b');
if ($input === false || $output === false) throw new RuntimeException('language_stdio_stream_failed');
$frames->write($input, $packet(new LanguageMessage('request', 'initialize', 11, [
    'workspace_uri' => $workspaceUri, 'process_id' => null, 'position_encodings' => ['utf-16'],
]), 'stdio-session'));
$frames->write($input, $packet(new LanguageMessage('notification', 'initialized', null, []), 'stdio-session'));
$frames->write($input, $packet(new LanguageMessage('request', 'shutdown', 12, []), 'stdio-session'));
$frames->write($input, $packet(new LanguageMessage('notification', 'exit', null, []), 'stdio-session'));
rewind($input);
if ($server->serve($input, $output) !== 0) throw new RuntimeException('language_stdio_server_failed');
rewind($output);
if ($decode($frames->read($output))->method !== 'initialize' || $decode($frames->read($output))->method !== 'shutdown') {
    throw new RuntimeException('language_stdio_responses_failed');
}
fclose($input); fclose($output);

jas_test_remove_tree($root);
echo "JAS LANGUAGE BINARY SERVICE: PASS\n";
