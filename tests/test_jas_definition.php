<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Definition\ApplicationDefinition;
use Jah\JAS\Persistence\WalJournal;
use Jah\JAS\Persistence\IdempotencyStore;
use Jah\JAS\Persistence\EventJournal;
use Jah\JAS\Persistence\EventCursorStore;
use Jah\JAS\Persistence\EventReceiptStore;
use Jah\JAS\Persistence\AuditJournal;
use Jah\JAS\Persistence\OutboxJournal;
use Jah\JAS\Recovery\RecoveryCoordinator;
use Jah\JAS\Runtime\EventProcessor;
use Jah\JAS\Runtime\GovernedRuntime;
use Jah\JAS\Security\CapabilityPolicy;
use Jah\JAS\Type\TypeRegistry;
use Jah\JAS\Jas;
use Jah\JAS\Definition\CompatibilityChecker;
use Jah\DataCore\DataCoreDatabase;
use Jah\DataCore\DataCoreTransactionManager;
use Jah\DataCore\DataCoreTurbo;

$throws = static function (callable $operation, string $expected): void {
    try { $operation(); } catch (Throwable $e) {
        if ($e->getMessage() === $expected) return;
        throw $e;
    }
    throw new RuntimeException("Expected {$expected}");
};

$app = (new ApplicationDefinition('Red Social Nacional'))
    ->domain('Identidad', 'identidad')
    ->domain('Publicaciones', 'publicacion', ['Identidad'])
    ->domain('Feeds', 'feed', ['Publicaciones'])
    ->action('Identidad', 'identidad.usuario.registrar')
    ->action('Publicaciones', 'publicacion.crear')
    ->action('Feeds', 'feed.distribuir');
$app->validate();
$app->assertCallAllowed('publicacion.crear', 'identidad.usuario.registrar');
$throws(fn() => $app->assertCallAllowed('identidad.usuario.registrar', 'publicacion.crear'), 'cross_domain_dependency_not_declared');
$throws(fn() => $app->action('Feeds', 'publicacion.invadir'), 'action_outside_domain');

$cycle = (new ApplicationDefinition('Sistema Ciclico'))
    ->domain('Usuarios', 'usuario', ['Mensajes'])
    ->domain('Mensajes', 'mensaje', ['Usuarios']);
$throws(fn() => $cycle->validate(), 'domain_dependency_cycle');

$missing = (new ApplicationDefinition('Sistema Incompleto'))->domain('Tramites', 'tramite', ['Identidad']);
$throws(fn() => $missing->validate(), 'domain_dependency_not_defined');

$description = $app->describe();
if (($description['domains']['Feeds']['dependencies'][0] ?? null) !== 'Publicaciones') {
    throw new RuntimeException('application_description_invalid');
}

$enterprise = (new ApplicationDefinition('Portal Empresarial'))
    ->domain('Identidad', 'identidad')
    ->domain('Tramites', 'tramite', ['Identidad']);
$enterprise->defineEvent('Tramites', 'tramite.creado', 'TramiteCreado', 1);
$enterprise->defineAction('Identidad', 'identidad.consultar')
    ->input('ConsultaIdentidad')->output('IdentidadEncontrada')
    ->requires('identidad.read')->audit();
$enterprise->defineAction('Tramites', 'tramite.crear')
    ->input('NuevoTramite')->output('TramiteCreado')
    ->requires('tramite.create')->audit()->transactional()->idempotent()
    ->emits('tramite.creado');
$enterprise->defineAction('Tramites', 'tramite.notificar')
    ->input('TramiteCreado')->output('NotificacionAceptada')
    ->requires('tramite.notify')->audit()->idempotent()
    ->queued('notificaciones', 'ciudadano_id', 5);
$enterprise->validateForProduction();

$unsafe = (new ApplicationDefinition('Portal Inseguro'))->domain('Pagos', 'pago');
$unsafe->defineAction('Pagos', 'pago.cobrar')->input('Cobro')->output('CobroAceptado')->requires('pago.create')->audit()->transactional();
$throws(fn() => $unsafe->validateForProduction(), 'action_idempotency_required');

$types = (new TypeRegistry())
    ->define('NuevoTramite', ['id' => 'identifier', 'ciudadano_id' => 'identifier', 'asunto' => 'non-empty-string'])
    ->define('TramiteCreado', ['id' => 'identifier', 'version' => 'positive-int']);
$types->alias('Folio', 'identifier');
if (!$types->validate('Folio', 'TRAMITE-1') || $types->validate('Folio', 'folio invalido')) throw new RuntimeException('unified_type_alias_failed');
$runtimeApp = (new ApplicationDefinition('Runtime Gubernamental'))->domain('Tramites', 'tramite');
$runtimeApp->defineEvent('Tramites', 'tramite.creado', 'TramiteCreado');
$runtimeApp->defineAction('Tramites', 'tramite.crear')
    ->input('NuevoTramite')->output('TramiteCreado')->requires('tramite.create')
    ->audit()->transactional()->idempotent();
$runtimeDirectory = sys_get_temp_dir() . '/jas_governed_' . bin2hex(random_bytes(4));
$governedOutbox = new OutboxJournal($runtimeDirectory . '/outbox');
$governed = new GovernedRuntime(
    $runtimeApp,
    $types,
    new CapabilityPolicy(['funcionario' => ['tramite.create']]),
    new WalJournal($runtimeDirectory . '/wal'),
    'funcionario',
    new IdempotencyStore($runtimeDirectory . '/idempotency'),
    null,
    new AuditJournal($runtimeDirectory . '/audit'),
    $governedOutbox
);
$executions = 0;
$governed->handle('tramite.crear', static function (array $input) use (&$executions): array {
    $executions++;
    return ['id' => $input['id'], 'version' => 1];
});
$created = $governed->execute('tramite.crear', ['id' => 'TRAMITE-1', 'ciudadano_id' => 'CURP-1', 'asunto' => 'Licencia'], 'request-1');
if (($created['result']['version'] ?? null) !== 1) throw new RuntimeException('governed_runtime_failed');
if (!(new AuditJournal($runtimeDirectory . '/audit'))->verify()) throw new RuntimeException('audit_integrity_failed');
$replayed = $governed->execute('tramite.crear', ['id' => 'TRAMITE-1', 'ciudadano_id' => 'CURP-1', 'asunto' => 'Licencia'], 'request-1');
if ($executions !== 1 || ($replayed['replayed'] ?? false) !== true) throw new RuntimeException('idempotency_replay_failed');
$throws(
    fn() => $governed->execute('tramite.crear', ['id' => 'TRAMITE-DIFFERENT', 'ciudadano_id' => 'CURP-1', 'asunto' => 'Licencia'], 'request-1'),
    'idempotency_input_mismatch'
);
$governedOutbox->prepare('recovery-1', 'tramite.crear', [
    'result' => ['success' => true, 'result' => ['id' => 'TRAMITE-R', 'version' => 1], 'request_id' => 'recovery-1', 'action' => 'tramite.crear'],
    'event' => null, 'audit' => true, 'principal' => 'funcionario',
    'input_fingerprint' => hash('sha256', 'recovery'), 'idempotent' => false,
]);
$recoveryTypes = (new TypeRegistry())->define('RecoveryState', ['id' => 'identifier']);
$recoveryDatabase = (new DataCoreDatabase(
    new DataCoreTurbo($runtimeDirectory . '/recovery-state', 1),
    $recoveryTypes,
    $runtimeDirectory . '/recovery-locks',
    random_bytes(32),
))->collection('recovery_state', 'RecoveryState');
$recoveryCoordinator = new RecoveryCoordinator(
    new DataCoreTransactionManager($recoveryDatabase, $runtimeDirectory . '/recovery-transactions'),
    $governed,
);
$recoveryReport = $recoveryCoordinator->recover();
if (($recoveryReport['transactions_recovered'] ?? null) !== 0
    || ($recoveryReport['outbox_recovered'] ?? null) !== 1
    || ($recoveryReport['remaining_transactions'] ?? null) !== 0
    || $governedOutbox->pending() !== []) {
    throw new RuntimeException('outbox_recovery_failed');
}
$secondRecovery = $recoveryCoordinator->recover();
if (($secondRecovery['transactions_recovered'] ?? null) !== 0
    || ($secondRecovery['outbox_recovered'] ?? null) !== 0
    || $governedOutbox->pending() !== []) {
    throw new RuntimeException('outbox_recovery_not_idempotent');
}
$gapStore = new IdempotencyStore($runtimeDirectory . '/idempotency-gap');
$gapExecutions = 0;
$gapOperation = function () use (&$gapExecutions): array {
    $gapExecutions++;
    return ['success' => true];
};
$throws(
    fn() => $gapStore->executeOnce(
        'tramite.crear',
        'gap-request',
        'gap-fingerprint',
        $gapOperation,
        static fn() => throw new RuntimeException('simulated_outbox_close_failure'),
    ),
    'simulated_outbox_close_failure',
);
$gapReplay = $gapStore->executeOnce(
    'tramite.crear',
    'gap-request',
    'gap-fingerprint',
    function () use (&$gapExecutions): array {
        $gapExecutions++;
        return ['success' => false];
    },
);
if ($gapExecutions !== 1 || ($gapReplay['replayed'] ?? false) !== true) {
    throw new RuntimeException('idempotency_crash_gap_reexecuted');
}
$throws(
    fn() => $governed->execute('tramite.crear', ['id' => 'TRAMITE-2', 'ciudadano_id' => 'CURP-2', 'asunto' => '', 'hidden' => true]),
    'input_type_mismatch:NuevoTramite'
);

$simple = Jas::application('Red Social Segura')
    ->type('NuevaPublicacion', ['id' => 'identifier', 'autor_id' => 'identifier', 'contenido' => 'non-empty-string'])
    ->type('PublicacionCreada', ['id' => 'identifier', 'version' => 'positive-int'])
    ->domain('Publicaciones', 'publicacion')
    ->event('Publicaciones', 'publicacion.creada', 'PublicacionCreada');
$simple->action('Publicaciones', 'publicacion.crear')
    ->input('NuevaPublicacion')->output('PublicacionCreada')
    ->requires('publicaciones.create')->audit()->transactional()->idempotent()
    ->emits('publicacion.creada');
$simple->validate();
$previousManifest = $simple->describe();
$compatibleApp = Jas::application('Red Social Segura')
    ->type('NuevaPublicacion', ['id' => 'identifier', 'autor_id' => 'identifier', 'contenido' => 'non-empty-string', 'etiqueta?' => 'string'])
    ->type('PublicacionCreada', ['id' => 'identifier', 'version' => 'positive-int'])
    ->domain('Publicaciones', 'publicacion')
    ->event('Publicaciones', 'publicacion.creada', 'PublicacionCreada');
$compatibleApp->action('Publicaciones', 'publicacion.crear')->input('NuevaPublicacion')->output('PublicacionCreada')
    ->requires('publicaciones.create')->audit()->transactional()->idempotent()->emits('publicacion.creada');
$compatibleApp->validate();
$compatibility = (new CompatibilityChecker())->compare($previousManifest, $compatibleApp->describe());
if (!$compatibility->compatible() || $compatibility->warnings === []) throw new RuntimeException('compatible_manifest_rejected');

$breakingApp = Jas::application('Red Social Segura')
    ->type('NuevaPublicacion', ['id' => 'identifier', 'autor_id' => 'identifier'])
    ->type('PublicacionCreada', ['id' => 'identifier', 'version' => 'positive-int'])
    ->domain('Publicaciones', 'publicacion')
    ->event('Publicaciones', 'publicacion.creada', 'PublicacionCreada');
$breakingApp->action('Publicaciones', 'publicacion.crear')->input('NuevaPublicacion')->output('PublicacionCreada')
    ->requires('publicaciones.create')->audit()->transactional()->idempotent()->emits('publicacion.creada');
$breakingApp->validate();
if ((new CompatibilityChecker())->compare($previousManifest, $breakingApp->describe())->compatible()) throw new RuntimeException('breaking_manifest_accepted');
$simpleDirectory = sys_get_temp_dir() . '/jas_simple_' . bin2hex(random_bytes(4));
$simpleRuntime = $simple->runtime(['web' => ['publicaciones.create']], 'web', $simpleDirectory);
$simpleRuntime->handle('publicacion.crear', static fn(array $input): array => ['id' => $input['id'], 'version' => 1]);
$published = $simpleRuntime->execute('publicacion.crear', ['id' => 'POST-1', 'autor_id' => 'USER-1', 'contenido' => 'Hola JAS'], 'publish-1');
if (($published['event']['name'] ?? null) !== 'publicacion.creada') throw new RuntimeException('typed_event_not_emitted');
$eventJournal = new EventJournal($simpleDirectory . '/events');
if (!$eventJournal->verify() || count($eventJournal->all()) !== 1) throw new RuntimeException('event_journal_invalid');
$simpleRuntime->execute('publicacion.crear', ['id' => 'POST-1', 'autor_id' => 'USER-1', 'contenido' => 'Hola JAS'], 'publish-1');
if (count($eventJournal->all()) !== 1) throw new RuntimeException('replay_duplicated_event');
$feed = [];
$processor = (new EventProcessor(
    'feeds.projection',
    $eventJournal,
    new EventCursorStore($simpleDirectory . '/cursors'),
    new EventReceiptStore($simpleDirectory . '/receipts')
))
    ->on('publicacion.creada', static function (array $payload) use (&$feed): void { $feed[$payload['id']] = $payload; });
if ($processor->run() !== 1 || !isset($feed['POST-1'])) throw new RuntimeException('event_projection_failed');
if ($processor->run() !== 0) throw new RuntimeException('event_cursor_reprocessed');
$recoveryExecutions = 0;
$recoveredProcessor = (new EventProcessor(
    'feeds.projection',
    $eventJournal,
    new EventCursorStore($simpleDirectory . '/recovered-cursors'),
    new EventReceiptStore($simpleDirectory . '/receipts')
))->on('publicacion.creada', static function () use (&$recoveryExecutions): void { $recoveryExecutions++; });
if ($recoveredProcessor->run() !== 0 || $recoveryExecutions !== 0) throw new RuntimeException('event_receipt_reprocessed');

echo "JAS DEFINITION: PASS\n";
