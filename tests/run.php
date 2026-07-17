<?php

declare(strict_types=1);

$boot = require dirname(__DIR__) . '/app/bootstrap.php';
$config = $boot['config'];
$base = sys_get_temp_dir() . '/jah_product_tests_' . bin2hex(random_bytes(6));
$config['paths']['datacore_storage'] = $base . '/datacore';
$config['paths']['hot_storage'] = $base . '/pyramid';
$config['salk']['audit_file'] = $base . '/security/audit.jahl';

require_once dirname(__DIR__) . '/app/actions/JasContextRuntime.php';

use Jah\DataCore\DataCoreTurbo;
use Jah\DataCore\ReplicationAgent;
use Jah\DataCore\WorkerPool;
use Jah\JAS\Action\ActionScript;
use Jah\Http\RequestGuard;
use Jah\Memory\TieredMemory;

$passed = 0;
$failed = 0;

function check(string $name, callable $test): void
{
    global $passed, $failed;
    try {
        $test();
        $passed++;
        echo "PASS {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL {$name}: {$e->getMessage()}\n";
    }
}

function expectTrue(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$memory = new TieredMemory($config['paths']['datacore_storage'], $config['paths']['hot_storage']);
$runtime = new JasContextRuntime($memory, $config);

check('csrf_tokens_are_enforced', function () use ($base): void {
    putenv('JAH_SESSION_PATH=' . $base . '/sessions');
    $token = RequestGuard::csrfToken();
    RequestGuard::assertCsrf($token);
    $conversationId = RequestGuard::conversationId();
    expectTrue($conversationId === RequestGuard::conversationId(), 'browser conversation id changed inside the session');
    try {
        RequestGuard::assertCsrf('invalid');
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException('invalid CSRF token was accepted');
});

check('action_results_store_generated_knowledge', function () use ($runtime): void {
    $message = 'Dame un resumen del libro Max Demian';
    $classification = ActionScript::run('memory.classify_input', ['message' => $message]);
    $decision = $classification['result'] ?? [];
    expectTrue(($decision['store'] ?? false) === true, 'book summary request was not selected for memory');
    expectTrue(($decision['store_response'] ?? false) === true, 'classifier selected the prompt instead of the generated summary');
    expectTrue(($decision['type'] ?? '') === 'knowledge_summary', 'unexpected summary classification');
    foreach (['Hazme una sinopsis de Demian', '¿De qué trata el libro Demian?', 'Summarize the book Demian'] as $variant) {
        $variantResult = ActionScript::run('memory.classify_input', ['message' => $variant]);
        expectTrue(($variantResult['result']['store_response'] ?? false) === true, "summary variant was not classified: {$variant}");
    }

    $stored = ActionScript::run('memory.store_interaction', [
        'message' => $message,
        'response' => 'Max Demian es una novela de Hermann Hesse sobre identidad, dualidad y crecimiento personal.',
        'collection' => 'book-memory',
        'classification' => $decision,
    ]);
    expectTrue(($stored['result']['result_stored'] ?? false) === true, 'JAS action result was not persisted');
    expectTrue(($stored['result']['stored_source'] ?? '') === 'jas_action_result', 'wrong memory source was persisted');
    expectTrue(($stored['result']['tier'] ?? '') === 'warm', 'non-permanent generated knowledge was not routed to seven-day Warm memory');

    $updated = ActionScript::run('memory.store_interaction', [
        'message' => $message,
        'response' => 'Max Demian es una novela de Hermann Hesse sobre identidad, dualidad, crecimiento personal y autoconocimiento.',
        'collection' => 'book-memory',
        'classification' => $decision,
    ]);
    expectTrue(($updated['result']['memory_id'] ?? '') === ($stored['result']['memory_id'] ?? null), 'repeated summary created a duplicate identity');

    $found = $runtime->search('Hermann Hesse', 'book-memory');
    $memories = $found['result']['memories'] ?? [];
    expectTrue(count($memories) === 1, 'stored book summary was not retrievable');
    expectTrue(str_contains((string)($memories[0]['content'] ?? ''), 'autoconocimiento'), 'retrieved memory does not contain the latest generated summary');

    $permanentMessage = 'Guarda en memoria un resumen de Siddhartha';
    $permanentDecision = ActionScript::run('memory.classify_input', ['message' => $permanentMessage]);
    expectTrue(($permanentDecision['result']['permanent'] ?? false) === true, 'explicit save intent was lost during summary classification');
    $permanentSummary = ActionScript::run('memory.store_interaction', [
        'message' => $permanentMessage,
        'response' => 'Siddhartha explora la búsqueda espiritual y el aprendizaje por experiencia.',
        'collection' => 'book-memory',
        'classification' => $permanentDecision['result'] ?? [],
    ]);
    expectTrue(($permanentSummary['result']['tier'] ?? '') === 'cold', 'explicitly saved summary was not permanent Cold memory');
});

check('classification_routes_long_and_important_memory', function () use ($runtime): void {
    $importantMessage = 'Recuerda que mi lenguaje favorito es PHP';
    $importantDecision = ActionScript::run('memory.classify_input', ['message' => $importantMessage]);
    $important = ActionScript::run('memory.store_interaction', [
        'message' => $importantMessage,
        'response' => 'Lo recordaré.',
        'collection' => 'tier-routing',
        'classification' => $importantDecision['result'] ?? [],
    ]);
    expectTrue(($important['result']['tier'] ?? '') === 'cold', 'explicit important memory was not routed to Cold');

    $longMessage = str_repeat('Este es contexto extenso de una conversación de trabajo. ', 3);
    $longDecision = ActionScript::run('memory.classify_input', ['message' => $longMessage]);
    expectTrue(($longDecision['result']['type'] ?? '') === 'long_context', 'long conversation was not classified as long context');
    $long = ActionScript::run('memory.store_interaction', [
        'message' => $longMessage,
        'response' => 'Contexto recibido.',
        'collection' => 'tier-routing',
        'classification' => $longDecision['result'] ?? [],
    ]);
    expectTrue(($long['result']['tier'] ?? '') === 'warm', 'long non-important context was not routed to Warm');
});

check('api_access_key_is_enforced', function () use ($config): void {
    $previous = $_ENV['JAH_API_KEY'] ?? null;
    $_ENV['JAH_API_KEY'] = 'test-access-key';
    putenv('JAH_API_KEY=test-access-key');
    $_SERVER['HTTP_X_JAH_API_KEY'] = 'wrong';
    try {
        RequestGuard::authorize($config);
    } catch (RuntimeException) {
        $_SERVER['HTTP_X_JAH_API_KEY'] = 'test-access-key';
        RequestGuard::authorize($config);
        unset($_SERVER['HTTP_X_JAH_API_KEY']);
        putenv('JAH_API_KEY');
        if ($previous === null) unset($_ENV['JAH_API_KEY']);
        else $_ENV['JAH_API_KEY'] = $previous;
        return;
    }
    throw new RuntimeException('invalid API access key was accepted');
});

check('tiers_are_deduplicated', function () use ($runtime): void {
    foreach (['hot', 'warm', 'cold'] as $tier) {
        $runtime->save($tier, ['content' => "marker_{$tier}"], $tier, 'alpha');
        $result = $runtime->search("marker_{$tier}", 'alpha');
        expectTrue(count($result['result']['memories'] ?? []) === 1, "{$tier} returned duplicate records");
    }
});

check('collections_are_isolated', function () use ($runtime): void {
    $runtime->save('isolated', ['content' => 'collection_marker'], 'warm', 'alpha');
    $other = $runtime->search('collection_marker', 'beta');
    expectTrue(($other['result']['memories'] ?? []) === [], 'memory crossed collection boundary');
});

check('delete_hides_archived_record', function () use ($runtime): void {
    $runtime->save('forgotten', ['content' => 'forget_marker'], 'cold', 'alpha');
    $runtime->delete('forgotten', 'alpha');
    $result = $runtime->search('forget_marker', 'alpha');
    expectTrue(($result['result']['memories'] ?? []) === [], 'deleted record was resurrected');
});

check('migration_respects_collection_and_tier', function () use ($runtime): void {
    $runtime->save('old', ['content' => 'migration_marker', '_ts' => time() - 90_000], 'hot', 'alpha');
    $first = $runtime->migrate('alpha');
    $second = $runtime->migrate('alpha');
    $record = $runtime->retrieve('old', 'alpha');
    expectTrue(($first['result']['hot_to_warm'] ?? 0) === 1, 'hot record did not migrate to warm');
    expectTrue(($second['result']['warm_expired'] ?? -1) === 0, 'Warm record expired before seven days');
    expectTrue(($record['result']['memory']['_tier'] ?? '') === 'warm', 'Warm record incorrectly became permanent Cold memory');

    $runtime->save('expired-warm', ['content' => 'temporary', '_ts' => time() - 8 * 86400], 'warm', 'alpha');
    $runtime->save('permanent-cold', ['content' => 'permanent', '_ts' => time() - 30 * 86400], 'cold', 'alpha');
    expectTrue(($runtime->retrieve('expired-warm', 'alpha')['result']['found'] ?? true) === false, 'Warm TTL required a manual migration before hiding expired memory');
    expectTrue(($runtime->search('temporary', 'alpha')['result']['memories'] ?? ['unexpected']) === [], 'expired Warm memory appeared in search');
    $expiry = $runtime->migrate('alpha');
    expectTrue(($expiry['result']['warm_expired'] ?? 0) === 1, 'Warm memory did not expire after seven days');
    expectTrue(($runtime->retrieve('expired-warm', 'alpha')['result']['found'] ?? true) === false, 'expired Warm memory remained retrievable');
    expectTrue(($runtime->retrieve('permanent-cold', 'alpha')['result']['found'] ?? false) === true, 'permanent Cold memory expired');
});

check('sensitive_fields_are_rejected', function () use ($runtime): void {
    $result = $runtime->save('secret', ['password' => 'ordinary-password'], 'hot', 'alpha');
    expectTrue(($result['result']['saved'] ?? true) === false, 'sensitive field was stored');
});

check('batch_ids_are_retrievable', function () use ($base): void {
    $db = new DataCoreTurbo($base . '/batch', 10);
    $db->batchInsert('docs', [['content' => 'batch']]);
    $rows = $db->query('docs', static fn(array $doc): bool => true);
    $id = (string)($rows[0]['id'] ?? '');
    expectTrue($id !== '' && $db->find('docs', $id) !== null, 'generated batch id is not indexed');
    $db->close();
});

check('delimiter_ids_are_retrievable', function () use ($base): void {
    $db = new DataCoreTurbo($base . '/delimiter', 1);
    $db->insert('docs', ['id' => 'colon:id', 'content' => 'ok']);
    expectTrue($db->find('docs', 'colon:id') !== null, 'encoded index id was not found');
    $db->close();
});

check('inverted_index_rebuilds_automatically', function () use ($base): void {
    $path = $base . '/rebuild';
    $db = new DataCoreTurbo($path, 1);
    $db->insert('docs', ['id' => 'legacy', 'content' => 'automatic rebuild marker 2026']);
    $db->close();
    @unlink($path . '/index/terms/docs/.ready');

    $db = new DataCoreTurbo($path, 1);
    $results = $db->searchIndexed('docs', 'rebuild', 10);
    expectTrue(count($results) === 1 && ($results[0]['id'] ?? '') === 'legacy', 'index rebuild lost existing data');
    $db->close();
});

check('stale_postings_do_not_resurrect_updates', function () use ($runtime): void {
    $runtime->save('updated', ['content' => 'old_unique_term'], 'hot', 'index-update');
    $runtime->save('updated', ['content' => 'new_unique_term'], 'hot', 'index-update');
    $old = $runtime->search('old_unique_term', 'index-update');
    $new = $runtime->search('new_unique_term', 'index-update');
    expectTrue(($old['result']['memories'] ?? []) === [], 'stale posting returned obsolete content');
    expectTrue(count($new['result']['memories'] ?? []) === 1, 'updated term was not indexed');
});

check('search_reports_index_metrics', function () use ($runtime): void {
    $runtime->save('metric', ['content' => 'indexed_metric_term'], 'hot', 'metrics');
    $result = $runtime->search('indexed_metric_term', 'metrics');
    $metrics = $result['result']['metrics'] ?? [];
    expectTrue(($metrics['strategy'] ?? '') === 'datacore_inverted_index_v3', 'indexed strategy was not reported');
    expectTrue(isset($metrics['duration_ms'], $metrics['candidate_count']), 'search metrics are incomplete');
});

check('actionscript_rebuilds_datacore_indexes', function () use ($runtime): void {
    $result = $runtime->reindex('metrics');
    expectTrue(($result['success'] ?? false) === true, 'memory.reindex action failed');
    expectTrue(($result['result']['version'] ?? 0) === 3, 'unexpected DataCore index version');
    expectTrue(($result['result']['documents'] ?? 0) >= 1, 'reindex did not include active documents');
});

check('replication_is_signed_local_and_verifiable', function () use ($base): void {
    $primary = $base . '/replication-primary';
    $replica = $base . '/replication-copy';
    $agent = new ReplicationAgent($primary, 'test-replication-key-with-safe-length');
    $agent->addNode($replica);
    expectTrue($agent->replicate(['type' => 'memory.saved', 'id' => 'replicated']) === true, 'replication write failed');
    expectTrue($agent->verifyLog(), 'primary signature chain is invalid');
    expectTrue($agent->verifyLog($replica), 'replica signature chain is invalid');
    expectTrue(file_get_contents($primary . '/replication.log') === file_get_contents($replica . '/replication.log'), 'replica differs from primary');

    file_put_contents($replica . '/replication.log', 'tampered' . PHP_EOL, FILE_APPEND | LOCK_EX);
    expectTrue(!$agent->verifyLog($replica), 'tampered replica was accepted');
    expectTrue($agent->replicate(['type' => 'memory.updated', 'id' => 'replicated']) === true, 'replica did not recover');
    expectTrue($agent->verifyLog($replica), 'recovered replica chain is invalid');
});

check('worker_pool_reports_confirmed_inserts', function () use ($base): void {
    $poolPath = $base . '/worker-pool';
    $pool = new WorkerPool($poolPath, 2);
    $docs = [
        ['id' => 'pool-1', 'content' => 'one'],
        ['id' => 'pool-2', 'content' => 'two'],
        ['id' => 'pool-3', 'content' => 'three'],
        ['id' => 'pool-4', 'content' => 'four'],
    ];
    expectTrue($pool->parallelInsert('docs', $docs) === 4, 'worker pool returned an incorrect count');
    $storage = new \Jah\DataCore\StorageAgent($poolPath . '/data');
    expectTrue(count($storage->query('docs', static fn(array $row): bool => true)) === 4, 'worker pool did not persist every document');
    $storage->close();
});

check('pyramidal_conversation_context_survives_requests', function () use ($base): void {
    $storedByAction = ActionScript::run('memory.store_conversation', [
        'conversation_id' => 'action-thread',
        'collection' => 'action-conversation',
        'message' => 'Mi pregunta anterior fue sobre Demian',
        'response' => 'Demian es una novela de Hermann Hesse.',
    ]);
    expectTrue(($storedByAction['result']['stored'] ?? false) === true, 'ActionScript did not store the conversation exchange');
    $loadedByAction = ActionScript::run('memory.load_conversation', [
        'conversation_id' => 'action-thread',
        'collection' => 'action-conversation',
    ]);
    expectTrue(($loadedByAction['result']['count'] ?? 0) === 2, 'ActionScript did not load the prior exchange');

    $dataPath = $base . '/conversation/datacore';
    $pyramidPath = $base . '/conversation/pyramid';
    $conversationMemory = new TieredMemory($dataPath, $pyramidPath);
    $conversationMemory->appendConversationExchange(
        'thread-1',
        'Estoy leyendo Demian de Hermann Hesse',
        'Entendido, estás leyendo Demian.',
        'conversation-test'
    );
    $conversationMemory->appendConversationExchange(
        'thread-1',
        '¿Quién es el protagonista?',
        'El protagonista es Emil Sinclair.',
        'conversation-test'
    );
    $conversationMemory->appendConversationExchange(
        'thread-1',
        '¿Y quién lo guía?',
        'Max Demian guía a Sinclair.',
        'conversation-test'
    );
    expectTrue(count($conversationMemory->conversationTurns('thread-1', 'conversation-test')) === 6, 'Hot conversation lost stored turns');
    expectTrue($conversationMemory->search('Max Demian guía', 'conversation-test') === [], 'conversation state leaked into durable semantic search');
    $conversationMemory->close();

    $reopened = new TieredMemory($dataPath, $pyramidPath);
    $turns = $reopened->conversationTurns('thread-1', 'conversation-test');
    expectTrue(count($turns) === 6, 'complete conversation did not survive a new PHP request');
    expectTrue(($turns[0]['content'] ?? '') === 'Estoy leyendo Demian de Hermann Hesse', 'first Hot turn was lost');
    expectTrue(($turns[5]['content'] ?? '') === 'Max Demian guía a Sinclair.', 'latest assistant answer was not retained');

    $context = ActionScript::run('memory.build_context', [
        'message' => '¿Quién lo guía?',
        'memories' => [],
        'conversation' => $turns,
        'classification' => [],
    ]);
    $text = (string)($context['result']['context'] ?? '');
    expectTrue(str_contains($text, 'Emil Sinclair') && str_contains($text, 'Max Demian'), 'recent dialogue was not added to JAS context');
    $reopened->close();

    $longConversation = new TieredMemory($base . '/long-conversation/datacore', $base . '/long-conversation/pyramid');
    $longConversation->appendConversationExchange('long-thread', str_repeat('A', 1200), str_repeat('B', 1200), 'long-talk', 2000);
    $longConversation->appendConversationExchange('long-thread', str_repeat('C', 1200), str_repeat('D', 1200), 'long-talk', 2000);
    $longTurns = $longConversation->conversationTurns('long-thread', 'long-talk');
    expectTrue(count($longTurns) === 4, 'Warm transition lost conversation turns');
    expectTrue(($longTurns[0]['_conversation_tier'] ?? '') === 'warm', 'older long-conversation turns did not move to Warm');
    expectTrue(($longTurns[3]['_conversation_tier'] ?? '') === 'hot', 'latest long-conversation turns did not remain Hot');

    $expiredId = 'conversation_warm_' . substr(hash('sha256', 'expired-thread'), 0, 32);
    $longConversation->store($expiredId, [
        'role' => 'conversation',
        'conversation_id' => 'expired-thread',
        '_memory_kind' => 'conversation_warm',
        'turns' => [
            ['role' => 'user', 'content' => 'expired question', 'at' => microtime(true) - 8 * 86400],
            ['role' => 'assistant', 'content' => 'expired answer', 'at' => microtime(true) - 8 * 86400],
        ],
    ], 'warm', 'long-talk');
    expectTrue($longConversation->conversationTurns('expired-thread', 'long-talk') === [], 'conversation Warm turns remained after seven days');
    $longConversation->close();
});

$memory->close();
echo "SUMMARY {$passed}/" . ($passed + $failed) . "\n";
exit($failed === 0 ? 0 : 1);
