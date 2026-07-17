<?php

declare(strict_types=1);

$boot = require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Action\ActionGraph;
use Jah\JAS\Action\ActionNode;
use Jah\JAS\Action\GraphScheduler;
use Jah\JAS\ObjectGraph\ActiveObject;
use Jah\JAS\ObjectGraph\ObjectRuntime;
use Jah\JAS\Protocol\JasBinaryCodec;
use Jah\JAS\Protocol\JasPacket;
use Jah\JAS\Security\SalkPacketGuard;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $operation, string $expected): void {
    try { $operation(); } catch (Throwable $e) {
        if ($e->getMessage() === $expected) return;
        throw $e;
    }
    throw new RuntimeException("Expected {$expected}");
};

$key = str_repeat('k', 32);
$codec = new JasBinaryCodec(new SalkPacketGuard($key));
$packet = new JasPacket(100, 1, 'req-1', 'player-1', "\0binary\xff", time());
$decoded = $codec->decode($codec->encode($packet));
$assert($decoded->payload === $packet->payload, 'El protocolo binario alteró el payload');

$actions = [
    'input.read' => static fn(array $payload): array => ['success' => true, 'result' => ['key' => $payload['data']['key'] ?? null]],
    'player.move' => static fn(array $payload): array => ['success' => true, 'result' => ['x' => (($payload['state']['x'] ?? 0) + 1)]],
];
$scheduler = new GraphScheduler(static function (string $name, array $payload) use ($actions): array {
    if (!isset($actions[$name])) return ['success' => false, 'error' => 'unknown_action'];
    return $actions[$name]($payload);
});

$graph = (new ActionGraph())
    ->add(new ActionNode('input', 'input.read', ['data' => ['key' => 'W']], [], 10))
    ->add(new ActionNode('move', 'player.move', ['state' => ['x' => 0]], ['input'], 5));
$result = $scheduler->run($graph);
$assert($result['success'] === true, 'El scheduler no ejecutó el grafo');

$runtime = new ObjectRuntime($scheduler);
$runtime->register((new ActiveObject('player-1', 'GameEntity', ['x' => 0]))->on('keyboard.w', 'player.move'));
$eventResult = $runtime->emit('player-1', 'keyboard.w', ['key' => 'W']);
$assert($eventResult['success'] === true, 'El objeto activo no reaccionó al evento');

$cycle = (new ActionGraph())
    ->add(new ActionNode('a', 'test.a', [], ['b']))
    ->add(new ActionNode('b', 'test.b', [], ['a']));
$assertThrows(fn() => $scheduler->run($cycle), 'Ciclo detectado en el grafo JAS: a');
$assertThrows(fn() => new ActionNode('node', 'test.run', [], ['dep', 'dep']), 'Dependencias JAS duplicadas');
$assertThrows(fn() => new ActiveObject('invalid id', 'Entity'), 'ID de objeto JAS inválido');

$limited = new GraphScheduler(static fn(): array => ['success' => true], 1, 1, 10);
$tooLarge = (new ActionGraph())
    ->add(new ActionNode('one', 'test.one'))
    ->add(new ActionNode('two', 'test.two'));
$assertThrows(fn() => $limited->run($tooLarge), 'graph_node_limit_exceeded');

$suspending = new GraphScheduler(static function (): void { Fiber::suspend(); Fiber::suspend(); }, 1, 10, 1);
$waitGraph = (new ActionGraph())->add(new ActionNode('wait', 'test.wait'));
$assertThrows(fn() => $suspending->run($waitGraph), 'graph_tick_limit_exceeded');

echo "JAS CORE: PASS\n";
