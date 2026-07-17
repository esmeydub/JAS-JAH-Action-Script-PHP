<?php

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use Jah\JAS\Action\ActionScript;

$action = ActionScript::define('math.double');
$action
    ->requires(['value'])
    ->timeout(100)
    ->handler(static fn(array $data): int => (int) $data['value'] * 2);

$result = ActionScript::run('math.double', ['value' => 21]);
echo "Result: " . print_r($result, true);
