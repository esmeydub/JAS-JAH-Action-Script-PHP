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

$storagePath = (string)$config['paths']['datacore_storage'];
$hotStoragePath = (string)$config['paths']['hot_storage'];
$tiered = new TieredMemory($storagePath, $hotStoragePath);
require_once dirname(__DIR__) . '/app/actions/JasContextRuntime.php';
$runtime = new JasContextRuntime($tiered, $config);

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$input = [];
$action = $method === 'GET' ? 'status' : 'chat';
$collection = 'memories';
$conversationId = 'api-memories';

try {
    RequestGuard::assertMethod($method, (array)($config['security']['allowed_methods'] ?? ['GET', 'POST']));
    RequestGuard::authorize($config);
    $input = JahTransport::decodeRequest((int)($config['security']['max_payload_bytes'] ?? 1048576));
    $action = (string)($input['action'] ?? ($method === 'GET' ? 'status' : 'chat'));
    if ($action === '' || strlen($action) > (int)($config['security']['max_action_length'] ?? 80) || preg_match('/^[a-z_]+$/', $action) !== 1) {
        throw new RuntimeException('Acción inválida');
    }
    $collection = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($input['collection'] ?? 'memories')) ?: 'memories';
    $conversationId = trim((string)($input['conversation_id'] ?? ('api-' . $collection)));
    if (strlen($conversationId) > 128 || preg_match('/^[a-zA-Z0-9_.-]+$/', $conversationId) !== 1) {
        throw new RuntimeException('conversation_id inválido');
    }
    $readActions = ['status', 'salk_status', 'salk_package_vectors', 'stats', 'retrieve', 'get', 'search'];
    if ($method !== 'POST' && !in_array($action, $readActions, true)) {
        throw new RuntimeException('Esta acción requiere POST');
    }

    switch ($action) {
        case 'status':
            $salkStatus = $runtime->runSalkPreflight('api.status');
            $output = [
                'status' => 'success',
                'service' => 'JAS — JAH Action Script PHP',
                'runtime' => 'JAS — JAH Action Script PHP en PHP puro',
                'memory' => 'DataCoreTurbo + MemoryPyramid Hot/Warm/Cold',
                'salk' => $salkStatus['result'] ?? [],
            ];
            break;

        case 'salk_status':
            $salkStatus = $runtime->runSalkPreflight('api.salk_status');
            $salk = $salkStatus['result'] ?? [];
            $ok = (bool)($salk['ok'] ?? false);
            $output = [
                'status' => $ok ? 'success' : 'warning',
                'pass' => $ok,
                'message' => $ok ? 'SALK PASS: security checks completed without errors' : 'SALK REVIEW: security checks returned errors',
                'salk' => $salk,
            ];
            break;

        case 'salk_package_vectors':
            $vectors = $runtime->runSalkPackageVectorScan();
            $scan = $vectors['result'] ?? [];
            $ok = (bool)($scan['ok'] ?? false);
            $output = [
                'status' => $ok ? 'success' : 'warning',
                'pass' => $ok,
                'message' => $ok ? 'PACKAGE VECTOR PASS: no Node/npm artifacts detected' : 'PACKAGE VECTOR REVIEW: artifacts detected',
                'package_vectors' => $scan,
            ];
            break;

        case 'stats':
            $stats = $runtime->stats($collection);
            $data = $stats['result'] ?? [];
            $output = [
                'status' => 'success',
                'pass' => true,
                'message' => 'STATS PASS: statistics returned',
                'data' => $data,
            ];
            break;

        case 'save':
            $data = $input['data'] ?? $input['content'] ?? [];
            if (is_string($data)) $data = ['content' => $data];
            if (!is_array($data)) $data = [];
            $id = (string)($data['id'] ?? $input['id'] ?? bin2hex(random_bytes(8)));
            $tier = (string)($input['tier'] ?? $data['_tier'] ?? 'hot');
            $saved = $runtime->save($id, $data, $tier, $collection);
            $savedOk = (bool)($saved['success'] ?? false) && (bool)($saved['result']['saved'] ?? false);
            $output = ['status' => $savedOk ? 'success' : 'rejected', 'data' => $saved['result'] ?? []];
            if (isset($saved['error'])) $output['error'] = $saved['error'];
            break;

        case 'retrieve':
        case 'get':
            $id = trim((string)($input['id'] ?? ''));
            if ($id === '') throw new RuntimeException('id required');
            $found = $runtime->retrieve($id, $collection);
            $result = $found['result'] ?? [];
            $output = ($result['found'] ?? false)
                ? ['status' => 'success', 'data' => $result['memory']]
                : ['status' => 'not_found', 'id' => $id];
            break;

        case 'search':
            $query = trim((string)($input['query'] ?? ''));
            if ($query === '') throw new RuntimeException('query required');
            $limit = max(1, min((int)($input['limit'] ?? 20), 100));
            $found = $runtime->search($query, $collection, $limit);
            $memories = $found['result']['memories'] ?? [];
            $total = (int)($found['result']['count'] ?? count($memories));
            $output = [
                'status' => 'success',
                'pass' => true,
                'message' => 'SEARCH PASS: search executed',
                'query' => $query,
                'total' => $total,
                'metrics' => $found['result']['metrics'] ?? [],
                'data' => $memories,
            ];
            break;

        case 'delete':
        case 'forget':
            $id = trim((string)($input['id'] ?? ''));
            if ($id === '') throw new RuntimeException('id required');
            $deleted = $runtime->delete($id, $collection);
            $output = ['status' => 'success', 'data' => $deleted['result'] ?? ['id' => $id, 'forgotten' => true]];
            break;

        case 'batch':
            $docs = $input['docs'] ?? [];
            if (!is_array($docs)) throw new RuntimeException('docs array required');
            $saved = 0;
            foreach ($docs as $doc) {
                if (!is_array($doc)) continue;
                $id = (string)($doc['id'] ?? bin2hex(random_bytes(8)));
                $tier = (string)($doc['_tier'] ?? $input['tier'] ?? 'hot');
                $result = $runtime->save($id, $doc, $tier, $collection);
                if (($result['success'] ?? false) && ($result['result']['saved'] ?? false)) $saved++;
            }
            $output = ['status' => 'success', 'inserted' => $saved];
            break;

        case 'migrate':
            $migrated = $runtime->migrate($collection);
            $output = ['status' => 'success', 'data' => $migrated['result'] ?? []];
            break;

        case 'reindex':
            $indexed = $runtime->reindex($collection);
            $output = ['status' => ($indexed['success'] ?? false) ? 'success' : 'error', 'data' => $indexed['result'] ?? []];
            break;

        case 'chat':
        default:
            $message = trim((string)($input['message'] ?? ''));
            if ($message === '') throw new RuntimeException('message required');
            $agent = $runtime->executeContext($message, $collection, $conversationId);
            $agentOk = !($agent['blocked_by_salk'] ?? false) && !($agent['action_failed'] ?? false);
            $output = array_merge(['status' => $agentOk ? 'success' : 'error', 'runtime' => 'jas-local'], $agent);
            break;
    }
} catch (Throwable $e) {
    http_response_code(400);
    $output = ['status' => 'error', 'error' => $e->getMessage()];
}

$tiered->close();
JahTransport::respond($output, $runtime->getSalkGuard());
