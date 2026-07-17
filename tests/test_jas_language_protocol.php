<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Protocol\LanguageMessage;
use Jah\JAS\Protocol\LanguagePayloadCodec;
use Jah\JAS\Protocol\LanguageProtocolCodec;
use Jah\JAS\Protocol\JasBinaryCodec;
use Jah\JAS\Security\SalkPacketGuard;

$protocol = new LanguageProtocolCodec(new JasBinaryCodec(new SalkPacketGuard(str_repeat('L', 32))));
$position = ['uri' => 'file:///workspace/app/Types/Tramite.php', 'version' => 7, 'line' => 3, 'character' => 5, 'position_encoding' => 'utf-16'];
$diagnostic = [
    'code' => 'JAS-TYPE-001', 'severity' => 1, 'message' => 'Símbolo inválido',
    'start_line' => 3, 'start_character' => 5, 'end_line' => 3, 'end_character' => 12,
];
$messages = [
    new LanguageMessage('request', 'initialize', 1, [
        'workspace_uri' => 'file:///workspace', 'process_id' => 1234,
        'position_encodings' => ['utf-16', 'utf-8'],
    ]),
    new LanguageMessage('notification', 'initialized', null, []),
    new LanguageMessage('notification', 'textDocument/didOpen', null, [
        'uri' => $position['uri'], 'version' => 1, 'language_id' => 'jas-php',
        'content' => "<?php\n// á 😀\n",
    ]),
    new LanguageMessage('notification', 'textDocument/didChange', null, [
        'uri' => $position['uri'], 'version' => 2, 'changes' => [['text' => "<?php\n// cambio\n"]],
    ]),
    new LanguageMessage('notification', 'textDocument/didClose', null, ['uri' => $position['uri']]),
    new LanguageMessage('request', 'textDocument/hover', 'hover-1', $position),
    new LanguageMessage('request', 'textDocument/definition', 2, $position),
    new LanguageMessage('request', 'textDocument/references', 3, $position),
    new LanguageMessage('request', 'textDocument/prepareRename', 4, $position),
    new LanguageMessage('request', 'textDocument/rename', 5, $position + ['new_name' => 'SolicitudTramite']),
    new LanguageMessage('notification', 'textDocument/publishDiagnostics', null, [
        'uri' => $position['uri'], 'version' => 2, 'diagnostics' => [$diagnostic],
    ]),
    new LanguageMessage('request', 'shutdown', 6, []),
    new LanguageMessage('notification', 'exit', null, []),
    new LanguageMessage('response', 'textDocument/hover', 'hover-1', ['contents' => 'Tipo JAS']),
    new LanguageMessage('error', 'textDocument/definition', 2, ['code' => -32602, 'message' => 'Invalid params']),
];

$fixture = null;
foreach ($messages as $index => $message) {
    $binary = $protocol->encode($message, 'internal-' . $index, 'session-1', 1_800_000_000);
    if ($index === 0) $fixture = $binary;
    $roundTrip = $protocol->decode($binary);
    if ($roundTrip['message']->toArray() != $message->toArray()
        || $roundTrip['correlation_id'] !== 'internal-' . $index
        || $roundTrip['session_id'] !== 'session-1') {
        throw new RuntimeException('language_protocol_roundtrip_failed');
    }
}

$payload = new LanguagePayloadCodec();
$canonicalA = $payload->encode(['zeta' => 1, 'alpha' => ['á', true, null, -12]]);
$canonicalB = $payload->encode(['alpha' => ['á', true, null, -12], 'zeta' => 1]);
if (!hash_equals($canonicalA, $canonicalB) || $payload->decode($canonicalA) !== ['alpha' => ['á', true, null, -12], 'zeta' => 1]) {
    throw new RuntimeException('language_payload_not_canonical');
}

$reject = static function (callable $operation, string $expected): void {
    try { $operation(); } catch (RuntimeException $error) {
        if ($error->getMessage() === $expected) return;
        throw $error;
    }
    throw new RuntimeException('expected_' . $expected);
};
$reject(fn() => new LanguageMessage('notification', 'textDocument/hover', null, $position), 'language_message_direction_invalid');
$reject(fn() => new LanguageMessage('notification', 'initialized', 9, []), 'language_notification_id_forbidden');
$reject(fn() => new LanguageMessage('notification', 'textDocument/didChange', null, [
    'uri' => $position['uri'], 'version' => 1, 'changes' => [['text' => "bad\xFF"]],
]), 'language_message_field_invalid');
$reject(fn() => $payload->decode(substr($canonicalA, 0, -1)), 'language_payload_truncated');
$reject(fn() => $payload->encode(['bad key' => true]), 'language_payload_key_invalid');

if (isset($argv[1])) {
    if (!is_string($fixture) || file_put_contents((string) $argv[1], $fixture, LOCK_EX) !== strlen($fixture)) {
        throw new RuntimeException('language_c_fixture_write_failed');
    }
}

echo "JAS LANGUAGE BINARY CONTRACTS: PASS\n";
