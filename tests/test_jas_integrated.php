<?php

declare(strict_types=1);

$boot = require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\DataCore\PhpSerializer;
use Jah\JAS\Action\ActionGraph;
use Jah\JAS\Action\ActionNode;
use Jah\JAS\ObjectGraph\ActiveObject;
use Jah\JAS\ObjectGraph\ObjectRuntime;
use Jah\JAS\Persistence\ObjectStateStore;
use Jah\JAS\Persistence\WalJournal;
use Jah\JAS\Protocol\JasBinaryCodec;
use Jah\JAS\Protocol\JasPacket;
use Jah\JAS\Protocol\Opcodes;
use Jah\JAS\Runtime\BinaryRuntime;
use Jah\JAS\Runtime\JasRuntime;
use Jah\JAS\Security\CapabilityPolicy;
use Jah\JAS\Security\ReplayGuard;
use Jah\JAS\Security\SalkPacketGuard;
use Jah\JAS\Security\SalkRuntimeGuard;
use Jah\Memory\TieredMemory;

$assert = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$base = sys_get_temp_dir() . '/jas_integrated_' . bin2hex(random_bytes(5));
mkdir($base, 0700, true);

$memory = new TieredMemory($base . '/memory/datacore', $base . '/memory/pyramid');
$store = new ObjectStateStore($memory);
$policy = new CapabilityPolicy([
    'jas.local' => ['object.*', 'action.*'],
    'jas.native' => ['protocol.ping', 'object.event.emit', 'object.state.read', 'action.player.move'],
]);
$wal = new WalJournal($base . '/wal');
$runtime = new JasRuntime($policy, $wal, 'jas.local');
$runtime->register('player.move', 'object.state.write', static function(array $payload): array {
    return ['success'=>true, 'state_patch'=>['x'=>(int)($payload['state']['x'] ?? 0)+1]];
});
$objects = new ObjectRuntime($runtime->scheduler(), $store);
$objects->register((new ActiveObject('player-1', 'GameEntity', ['x'=>0]))->on('keyboard.w', 'player.move'));

$result = $objects->emit('player-1', 'keyboard.w', ['key'=>'W']);
$assert($result['success'] === true, 'Evento no ejecutado');
$assert(($result['object_state']['x'] ?? null) === 1, 'Estado no actualizado');

$restoredRuntime = new ObjectRuntime($runtime->scheduler(), $store);
$restored = $restoredRuntime->object('player-1');
$assert($restored instanceof ActiveObject && ($restored->state()['x'] ?? null) === 1, 'Objeto no restaurado desde DataCore');

$graph = (new ActionGraph())->add(new ActionNode('move2', 'player.move', ['state'=>['x'=>5]], []));
$graphResult = $runtime->run($graph);
$assert($graphResult['success'] === true, 'Grafo integrado falló');
$assert($wal->pending() === [], 'WAL dejó transacciones cerradas como pendientes');

$key = str_repeat('S', 32);
$codec = new JasBinaryCodec(new SalkPacketGuard($key));
$binary = new BinaryRuntime($codec, new SalkRuntimeGuard(new ReplayGuard($base . '/replay', 60), $policy), $runtime, $restoredRuntime);
$request = new JasPacket(Opcodes::OBJECT_EVENT, 0, 'req-' . bin2hex(random_bytes(6)), 'player-1', PhpSerializer::encode(['event'=>'keyboard.w','payload'=>['key'=>'W']]), time());
$responsePacket = $codec->decode($binary->handle($codec->encode($request)));
$response = PhpSerializer::decode($responsePacket->payload);
$assert(is_array($response) && ($response['success'] ?? false) === true, 'Runtime binario no ejecutó evento');
$assert(($response['object_state']['x'] ?? null) === 2, 'Runtime binario no persistió estado');

$replayResponse = PhpSerializer::decode($codec->decode($binary->handle($codec->encode($request)))->payload);
$assert(is_array($replayResponse) && ($replayResponse['success'] ?? true) === false && str_contains((string)($replayResponse['error'] ?? ''), 'Replay'), 'SALK no detectó replay');

$denied = new JasPacket(Opcodes::ACTION_EXECUTE, 0, 'req-' . bin2hex(random_bytes(6)), 'player-1', PhpSerializer::encode(['action'=>'admin.root','payload'=>[]]), time());
$deniedResponse = PhpSerializer::decode($codec->decode($binary->handle($codec->encode($denied)))->payload);
$assert(is_array($deniedResponse) && ($deniedResponse['success'] ?? true) === false, 'SALK no denegó capacidad');

echo "JAS INTEGRATED: PASS\n";
