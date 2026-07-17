<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once __DIR__ . '/../AsyncAction.php';
require_once __DIR__ . '/../JahEngineJas.php';
require_once __DIR__ . '/../JasAsyncActions.php';
require_once __DIR__ . '/../JasEventEmitter.php';
require_once __DIR__ . '/../JasPromise.php';
require_once __DIR__ . '/../JasStream.php';
require_once __DIR__ . '/../JasTypeScript.php';

use Jah\JAS\Action\ActionScript;
use Jah\JahEngineJas;
use Jah\JasAsyncActions;
use Jah\JasEventEmitter;
use Jah\JasPromise;
use Jah\JasStream;
use Jah\JasTypeScript;

$tests = [];

function test(string $name, callable $callback): void
{
    global $tests;
    try {
        $callback();
        $tests[$name] = true;
        echo "PASS {$name}\n";
    } catch (Throwable $error) {
        $tests[$name] = false;
        echo "FAIL {$name}: {$error->getMessage()}\n";
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

test('actions', function (): void {
    ActionScript::define('math.double')
        ->requires(['value'])
        ->handler(static fn(array $data): int => $data['value'] * 2);
    $result = ActionScript::run('math.double', ['value' => 21]);
    expect($result['success'] && $result['result'] === 42, 'action returned an incorrect result');
});

test('promise_chain', function (): void {
    $value = JasPromise::resolve(10)
        ->then(static fn(int $number): int => $number * 2)
        ->then(static fn(int $number): int => $number + 1)
        ->await();
    expect($value === 21, 'promise chain failed');
    expect(JasPromise::all([JasPromise::resolve(1), 2])->await() === [1, 2], 'promise all failed');
});

test('stream_pipeline', function (): void {
    $values = JasStream::from([1, 2, 3, 4])
        ->filter(static fn(int $value): bool => $value % 2 === 0)
        ->map(static fn(int $value): int => $value * 10)
        ->toArray();
    expect($values === [20, 40], 'stream pipeline failed');
});

test('events', function (): void {
    $events = new JasEventEmitter();
    $calls = 0;
    $events->once('ready', static function () use (&$calls): void { $calls++; });
    $events->emit('ready');
    $events->emit('ready');
    expect($calls === 1, 'once listener ran more than once');
});

test('runtime_types', function (): void {
    $types = new JasTypeScript();
    $types->define('Metric', ['name' => 'string', 'value' => 'number', 'unit?' => 'string']);
    expect($types->validate('Metric', ['name' => 'hps', 'value' => 42.5]), 'valid shape rejected');
    expect(!$types->validate('Metric', ['name' => 'hps', 'value' => false]), 'invalid shape accepted');
    expect($types->validate('int[]', [1, 2, 3]), 'typed array rejected');
    $types->defineStrict('GovernmentRecord', [
        'id' => 'identifier',
        'agency' => 'non-empty-string',
        'version' => 'positive-int',
        'metadata?' => 'map',
    ]);
    expect($types->validate('GovernmentRecord', ['id' => 'MX:EXP-1', 'agency' => 'Secretaria', 'version' => 1]), 'strict government record rejected');
    expect(!$types->validate('GovernmentRecord', ['id' => 'MX:EXP-1', 'agency' => 'Secretaria', 'version' => 1, 'hidden' => true]), 'strict shape accepted an undeclared field');
    expect(!$types->validate('identifier', 'invalid id with spaces'), 'invalid identifier accepted');
});

test('policy_engine', function (): void {
    $engine = new JahEngineJas();
    $engine->loadString(<<<'JAS'
policy("balanced")
observe(30s)
stability_windows(3)
workers(2, 8)
require("status_ok", "===", true)
require("temperature", "<", 80)
JAS);
    expect($engine->evaluate('balanced', ['status_ok' => true, 'temperature' => 65]), 'valid policy failed');
    expect(!$engine->evaluate('balanced', ['status_ok' => true, 'temperature' => 90]), 'invalid policy passed');
    expect($engine->getStats()['evaluations'] === 2, 'evaluations were not recorded');
});

test('task_runner', function (): void {
    $runner = new JasAsyncActions();
    $runner->addWorker(static fn(): int => 1);
    $runner->addWorker(static fn(): int => 2);
    expect($runner->runAll() === [1, 2], 'task runner failed');
});

$passed = count(array_filter($tests));
$total = count($tests);
echo "SUMMARY {$passed}/{$total}\n";
exit($passed === $total ? 0 : 1);
