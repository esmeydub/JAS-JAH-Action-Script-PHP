<?php

declare(strict_types=1);

$boot = require dirname(__DIR__) . '/app/bootstrap.php';
$config = $boot['config'];

use Jah\Memory\TieredMemory;
use Jah\Http\RequestGuard;

$request = array_merge($_GET, $_POST);
$requestedAction = (string)($request['action'] ?? 'chat');
$csrfToken = RequestGuard::csrfToken();
$conversationId = RequestGuard::conversationId();
$loginError = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        RequestGuard::assertCsrf((string)($request['csrf_token'] ?? ''));
    } catch (Throwable $e) {
        http_response_code(403);
        echo 'Petición rechazada: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        exit;
    }
}

if ($requestedAction === 'login' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (RequestGuard::loginBrowser((string)($request['access_key'] ?? ''))) {
        header('Location: index.php');
        exit;
    }
    $loginError = 'Clave de acceso inválida.';
}

if ($requestedAction === 'logout' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    RequestGuard::logoutBrowser();
    header('Location: index.php');
    exit;
}

if (!RequestGuard::browserIsAuthorized()) {
    $safeToken = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
    $safeError = htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Acceso JAH</title></head><body>';
    echo '<h1>JAS — JAH Action Script PHP</h1><p>Introduce JAH_API_KEY para continuar.</p>';
    if ($safeError !== '') echo '<p>' . $safeError . '</p>';
    echo '<form method="post"><input type="hidden" name="action" value="login"><input type="hidden" name="csrf_token" value="' . $safeToken . '"><input type="password" name="access_key" required><button type="submit">Entrar</button></form>';
    echo '</body></html>';
    exit;
}

$storagePath = (string)$config['paths']['datacore_storage'];
$hotStoragePath = (string)$config['paths']['hot_storage'];
$tiered = new TieredMemory($storagePath, $hotStoragePath);
require_once dirname(__DIR__) . '/app/actions/JasContextRuntime.php';
$runtime = new JasContextRuntime($tiered, $config);

$action = $requestedAction;
$collection = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($request['collection'] ?? 'memories')) ?: 'memories';
$id = trim((string)($request['id'] ?? ''));
$content = trim((string)($request['content'] ?? ''));
$message = trim((string)($request['message'] ?? ''));
$query = trim((string)($request['query'] ?? ''));
$requestedTier = (string)($request['tier'] ?? 'hot');
$tier = in_array($requestedTier, ['hot', 'warm', 'cold'], true) ? $requestedTier : 'hot';

$feedback = '';
$response = '';
$searchResults = [];
$statsData = null;
$contextPreview = '';
$conversationUsed = 0;
$conversationStoredResult = [];
$memoryResults = [];
$actionsTrace = [];
$classificationResult = [];
$storedResult = [];
$salkResult = [];

switch ($action) {
    case 'save':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($id !== '' && $content !== '') {
                $tags = array_values(array_filter(array_map('trim', explode(',', (string)($request['tags'] ?? '')))));
                $result = $runtime->save($id, ['id' => $id, 'content' => $content, 'tags' => $tags], $tier, $collection);
                $saved = (bool)($result['success'] ?? false) && (bool)($result['result']['saved'] ?? false);
                $feedback = $saved ? "Guardado en tier [{$tier}]: {$id}" : ('Error: ' . ($result['result']['reason'] ?? $result['error'] ?? 'no guardado'));
            } else {
                $feedback = 'Error: se requieren id y content';
            }
        }
        break;

    case 'search':
        if ($query !== '') {
            $result = $runtime->search($query, $collection, 30);
            $searchResults = $result['result']['memories'] ?? [];
        }
        break;

    case 'delete':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id !== '') {
            $result = $runtime->delete($id, $collection);
            $feedback = ($result['success'] ?? false) ? "Olvidado / Forgotten: {$id}" : ('Error: ' . ($result['error'] ?? 'no eliminado'));
            $action = 'search';
        }
        break;

    case 'migrate':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $feedback = 'Error: la migración requiere POST';
            $action = 'stats';
            break;
        }
        $result = $runtime->migrate($collection);
        $feedback = 'Migración ejecutada: ' . php_dump($result['result'] ?? []);
        $action = 'stats';
        $result = $runtime->stats($collection);
        $statsData = $result['result'] ?? [];
        break;

    case 'reindex':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $feedback = 'Error: reindex requiere POST';
            $action = 'stats';
            break;
        }
        $result = $runtime->reindex($collection);
        $feedback = 'Índice reconstruido: ' . php_dump($result['result'] ?? []);
        $action = 'stats';
        $result = $runtime->stats($collection);
        $statsData = $result['result'] ?? [];
        break;

    case 'stats':
        $result = $runtime->stats($collection);
        $statsData = $result['result'] ?? [];
        break;

    case 'salk_status':
        $result = $runtime->runSalkPreflight('index.salk_status');
        $salkResult = $result['result'] ?? [];
        break;

    case 'chat':
    default:
        $action = 'chat';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $message !== '') {
            $result = $runtime->executeContext(
                $message,
                $collection,
                $conversationId
            );
            $response = (string)($result['response'] ?? '');
            $contextPreview = (string)($result['context_preview'] ?? '');
            $conversationUsed = (int)($result['conversation_used'] ?? 0);
            $conversationStoredResult = is_array($result['conversation_stored'] ?? null) ? $result['conversation_stored'] : [];
            $memoryResults = is_array($result['memories'] ?? null) ? $result['memories'] : [];
            $actionsTrace = is_array($result['actions_trace'] ?? null) ? $result['actions_trace'] : [];
            $classificationResult = is_array($result['classification'] ?? null) ? $result['classification'] : [];
            $storedResult = is_array($result['stored'] ?? null) ? $result['stored'] : [];
            $salkResult = is_array($result['salk'] ?? null) ? $result['salk'] : [];
        }
        break;
}

$tiered->close();

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function php_dump(mixed $value): string {
    return var_export($value, true);
}
function brief(mixed $value, int $length = 220): string {
    $text = is_string($value) ? $value : php_dump($value);
    return substr($text, 0, $length);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>JAS — JAH Action Script PHP</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Courier New', monospace; background: #0f0f23; color: #e0e0e0; max-width: 1000px; margin: 0 auto; padding: 20px; }
        h1 { color: #00ff88; margin-bottom: 5px; }
        h2 { color: #00ff88; margin: 18px 0 10px; font-size: 1.1em; }
        .subtitle { color: #aaa; margin-bottom: 16px; font-size: 0.9em; }
        .runtime { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px; margin-bottom: 20px; }
        .chip { border: 1px solid #00ff88; border-radius: 6px; padding: 8px; background: #111936; color: #00ff88; font-size: 0.85em; }
        .nav { margin-bottom: 20px; }
        .nav a { color: #00ff88; margin: 0 8px 8px 0; display: inline-block; text-decoration: none; padding: 6px 10px; border: 1px solid #00ff88; border-radius: 3px; }
        .nav a:hover, .nav a.active { background: #00ff88; color: #0f0f23; }
        .inline-form { display: inline; background: none; padding: 0; margin: 0; }
        .inline-form button { padding: 6px 10px; margin: 0 8px 8px 0; border: 1px solid #00ff88; border-radius: 3px; font-weight: normal; }
        form { background: #1a1a3e; padding: 20px; border-radius: 8px; margin-bottom: 15px; }
        label { display: block; margin: 10px 0 5px; color: #aaa; }
        input, textarea, select { width: 100%; padding: 10px; background: #0f0f23; border: 1px solid #333; color: #e0e0e0; border-radius: 4px; font-family: inherit; }
        button { background: #00ff88; color: #0f0f23; border: none; padding: 12px 24px; cursor: pointer; border-radius: 4px; font-weight: bold; margin-top: 10px; font-family: inherit; }
        .response { background: #1a1a3e; padding: 20px; border-radius: 8px; border-left: 4px solid #00ff88; white-space: pre-wrap; margin-top: 15px; }
        .item { background: #1a1a3e; padding: 15px; margin: 8px 0; border-radius: 5px; border-left: 3px solid #555; }
        .item.hot { border-left-color: #ff6b6b; }
        .item.warm { border-left-color: #feca57; }
        .item.cold { border-left-color: #48dbfb; }
        .meta { color: #888; font-size: 0.85em; margin-top: 5px; }
        .error { color: #ff6666; }
        .success { color: #00ff88; }
        .info { color: #48dbfb; }
        pre { white-space: pre-wrap; background: #0f0f23; padding: 12px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>JAS — JAH Action Script PHP</h1>
    <p class="subtitle">JAH Action Script PHP + DataCoreTurbo + MemoryPyramid en PHP puro</p>

    <div class="runtime">
        <div class="chip">JAS: ACTIVE</div>
        <div class="chip">JAS Runtime: local</div>
        <div class="chip">DataCoreTurbo: binary memory</div>
        <div class="chip">MemoryPyramid: Hot / Warm / Cold</div>
        <div class="chip">SALK Security: ACTIVE</div>
    </div>

    <div class="nav">
        <a href="?action=chat" class="<?= $action === 'chat' ? 'active' : '' ?>">Chat</a>
        <a href="?action=save" class="<?= $action === 'save' ? 'active' : '' ?>">Guardar / Save</a>
        <a href="?action=search" class="<?= $action === 'search' ? 'active' : '' ?>">Buscar / Search</a>
        <a href="?action=stats" class="<?= $action === 'stats' ? 'active' : '' ?>">Estadísticas / Stats</a>
        <a href="?action=salk_status" class="<?= $action === 'salk_status' ? 'active' : '' ?>">SALK</a>
        <form method="POST" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="action" value="migrate"><button type="submit">Migrar tiers / Migrate</button></form>
        <form method="POST" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="action" value="reindex"><button type="submit">Reconstruir índice / Reindex</button></form>
        <form method="POST" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="action" value="logout"><button type="submit">Salir</button></form>
    </div>

    <?php if ($feedback !== ''): ?>
    <p class="<?= str_starts_with($feedback, 'Error') ? 'error' : 'success' ?>"><?= e($feedback) ?></p>
    <?php endif; ?>

    <?php if ($action === 'chat'): ?>
    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="action" value="chat">
        <label>Pregunta / Question:</label>
        <textarea name="message" rows="3" placeholder="Escribe tu mensaje... / Type your message..." required><?= e($message) ?></textarea>
        <button type="submit">Ejecutar acción JAS</button>
    </form>

    <?php if ($response !== ''): ?>
    <h2>Resultado JAS / JAS result</h2>
    <div class="response"><?= nl2br(e($response)) ?></div>

    <h2>Memoria conversacional / Conversation memory</h2>
    <div class="item hot">
        <strong>Conversación activa:</strong> <?= e(($conversationStoredResult['stored'] ?? false) ? 'sí / yes' : 'no') ?><br>
        <strong>Pirámide:</strong> <?= e($conversationStoredResult['tier'] ?? 'hot_warm') ?><br>
        <strong>Turnos almacenados:</strong> <?= e($conversationStoredResult['turn_count'] ?? 0) ?>
    </div>

    <h2>Decisión de memoria importante / Durable memory decision</h2>
    <div class="item">
        <strong>Guardar en Warm/Cold:</strong> <?= e(($classificationResult['store'] ?? false) ? 'sí / yes' : 'no') ?><br>
        <strong>Tipo / Type:</strong> <?= e($classificationResult['type'] ?? 'N/A') ?><br>
        <strong>Razón / Reason:</strong> <?= e($classificationResult['reason'] ?? 'N/A') ?><br>
        <strong>Resultado de guardado / Store result:</strong> <?= e(php_dump($storedResult)) ?>
    </div>

    <h2>Memorias recuperadas / Retrieved memories (<?= count($memoryResults) ?>)</h2>
    <?php if ($memoryResults === []): ?>
        <p class="info">No se recuperó memoria previa para esta pregunta.</p>
    <?php else: foreach ($memoryResults as $item): $tierClass = $item['_memory_tier'] ?? $item['_tier'] ?? 'hot'; ?>
        <div class="item <?= e($tierClass) ?>">
            <strong>ID:</strong> <?= e($item['id'] ?? 'N/A') ?>
            | <strong>Tier:</strong> <?= e($tierClass) ?><br>
            <?= e(brief($item['content'] ?? $item)) ?>
        </div>
    <?php endforeach; endif; ?>

    <h2>Contexto construido por JAS</h2>
    <div class="item hot">
        <strong>Conversación / Conversation:</strong> <?= e($conversationId) ?><br>
        <strong>Turnos previos usados / Previous turns used:</strong> <?= e($conversationUsed) ?>
    </div>
    <pre><?= e($contextPreview) ?></pre>

    <h2>SALK Security</h2>
    <pre><?= e(php_dump($salkResult)) ?></pre>

    <h2>JAS action trace</h2>
    <pre><?= e(php_dump($actionsTrace)) ?></pre>
    <?php endif; ?>

    <?php elseif ($action === 'save'): ?>
    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="action" value="save">
        <label>ID:</label>
        <input type="text" name="id" placeholder="identificador-unico" required>
        <label>Contenido / Content:</label>
        <textarea name="content" rows="4" placeholder="Información a guardar... / Information to save..." required></textarea>
        <label>Tags:</label>
        <input type="text" name="tags" placeholder="preference, project, memory">
        <label>Tier de memoria / Memory tier:</label>
        <select name="tier">
            <option value="hot">Hot / Caliente</option>
            <option value="warm">Warm / Tibia</option>
            <option value="cold">Cold / Fría</option>
        </select>
        <button type="submit">Guardar con JAS / Save</button>
    </form>

    <?php elseif ($action === 'search'): ?>
    <form method="GET" action="">
        <input type="hidden" name="action" value="search">
        <label>Buscar / Search:</label>
        <input type="text" name="query" value="<?= e($query) ?>" placeholder="Términos de búsqueda... / Search terms..." required>
        <button type="submit">Buscar / Search</button>
    </form>

    <?php if ($query !== ''): ?>
    <h2>Resultado de búsqueda / Search result</h2>
    <div class="item <?= $searchResults !== [] ? 'hot' : '' ?>">
        <strong>SEARCH <?= $searchResults !== [] ? 'PASS' : 'PASS - sin coincidencias' ?></strong><br>
        Query: <?= e($query) ?><br>
        Total: <?= count($searchResults) ?>
    </div>
    <?php endif; ?>

    <?php if ($searchResults !== []): ?>
    <h2>Memorias encontradas / Found memories</h2>
    <?php foreach ($searchResults as $item): $tierClass = $item['_memory_tier'] ?? $item['_tier'] ?? 'hot'; ?>
    <div class="item <?= e($tierClass) ?>">
        <strong>ID:</strong> <?= e($item['id'] ?? 'N/A') ?>
        | <strong>Rol:</strong> <?= e($item['role'] ?? 'memory') ?>
        | <strong>Tier:</strong> <?= e($tierClass) ?><br>
        <strong>Contenido:</strong> <?= e(brief($item['content'] ?? $item)) ?>
        <div class="meta">
            <?= e(date('Y-m-d H:i', (int)($item['_ts'] ?? time()))) ?>
            <?php if (isset($item['id'])): ?>
            | <form method="POST" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($item['id']) ?>"><button type="submit" class="error">Olvidar / Forget</button></form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php elseif ($query !== ''): ?>
    <p class="info">La búsqueda corrió correctamente, pero no hubo coincidencias para esa palabra.</p>
    <?php endif; ?>

    <?php elseif ($action === 'salk_status'): ?>
    <h2>SALK Status</h2>
    <div class="item <?= ($salkResult['ok'] ?? false) ? 'hot' : 'cold' ?>">
        <strong><?= ($salkResult['ok'] ?? false) ? 'SALK PASS' : 'SALK REVIEW' ?></strong><br>
        Contexto: <?= e($salkResult['context'] ?? 'index.salk_status') ?><br>
        Errores: <?= count($salkResult['errors'] ?? []) ?><br>
        Warnings: <?= count($salkResult['warnings'] ?? []) ?>
    </div>
    <h2>Checks</h2>
    <pre><?= e(php_dump($salkResult)) ?></pre>

    <?php else: ?>
    <h2>Estadísticas / Statistics</h2>
    <?php $stats = is_array($statsData ?? null) ? $statsData : []; ?>
    <div class="item hot">
        <strong>STATS PASS</strong><br>
        Estadísticas recuperadas correctamente.
    </div>
    <div class="runtime">
        <div class="chip">Hot entries: <?= e($stats['hot_entries'] ?? 0) ?></div>
        <div class="chip">Hot docs: <?= e($stats['hot_documents'] ?? 0) ?></div>
        <div class="chip">Warm files: <?= e($stats['warm_files'] ?? 0) ?></div>
        <div class="chip">Warm records: <?= e($stats['warm_records'] ?? 0) ?></div>
        <div class="chip">Cold files: <?= e($stats['cold_files'] ?? 0) ?></div>
    </div>
    <pre><?= e(php_dump($stats)) ?></pre>
    <?php endif; ?>
</body>
</html>
