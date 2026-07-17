<?php

declare(strict_types=1);

$app = require dirname(__DIR__) . '/app/application.php';
$app->validate();
echo "JAS APP: PASS\n";
