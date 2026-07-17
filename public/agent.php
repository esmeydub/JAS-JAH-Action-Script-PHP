<?php

declare(strict_types=1);

$boot = require dirname(__DIR__) . '/app/bootstrap.php';
$config = $boot['config'];

use Jah\Memory\TieredMemory;
use Jah\Http\JahTransport;
use Jah\Http\RequestGuard;

header('Content-Type: text/plain; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    RequestGuard::assertMethod(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['POST']);
    RequestGuard::authorize($config);
    $input = JahTransport::decodeRequest((int)($config['security']['max_payload_bytes'] ?? 1048576));
} catch (Throwable $e) {
    JahTransport::respond(['status' => 'error', 'error' => $e->getMessage()], null, 400);
    exit;
}

$userMessage = trim((string)($input['message'] ?? ''));
if ($userMessage === '') {
    JahTransport::respond(['status' => 'error', 'error' => 'No message provided.'], null, 400);
    exit;
}

$collection = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($input['collection'] ?? 'memories')) ?: 'memories';
$conversationId = trim((string)($input['conversation_id'] ?? ('api-' . $collection)));
if (strlen($conversationId) > 128 || preg_match('/^[a-zA-Z0-9_.-]+$/', $conversationId) !== 1) {
    JahTransport::respond(['status' => 'error', 'error' => 'Invalid conversation_id.'], null, 400);
    exit;
}

$storagePath = (string)$config['paths']['datacore_storage'];
$hotStoragePath = (string)$config['paths']['hot_storage'];

$tiered = new TieredMemory($storagePath, $hotStoragePath);
require_once dirname(__DIR__) . '/app/actions/JasContextRuntime.php';
$runtime = new JasContextRuntime($tiered, $config);

$result = $runtime->executeContext($userMessage, $collection, $conversationId);

$output = [
    'status' => 'success',
    'response' => $result['response'],
    'runtime' => 'jas-local',
    'context_used' => $result['context_used'],
    'conversation_used' => $result['conversation_used'] ?? 0,
    'conversation_id' => $result['conversation_id'] ?? $conversationId,
    'conversation_stored' => $result['conversation_stored'] ?? [],
    'context_preview' => $result['context_preview'],
    'memories' => $result['memories'],
    'memory_search' => $result['memory_search'] ?? [],
    'classification' => $result['classification'] ?? [],
    'stored' => $result['stored'] ?? [],
    'actions_trace' => $result['actions_trace'],
];

$tiered->close();
JahTransport::respond($output, $runtime->getSalkGuard());
