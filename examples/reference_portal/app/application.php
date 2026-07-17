<?php

declare(strict_types=1);

use Jah\JAS\Tooling\GeneratedApplicationLoader;

if (!class_exists(GeneratedApplicationLoader::class)) {
    $jasRoot = realpath((string) getenv('JAS_ROOT'));
    if ($jasRoot === false || !is_file($jasRoot . '/app/bootstrap.php')) throw new RuntimeException('JAS_ROOT is invalid');
    require_once $jasRoot . '/app/bootstrap.php';
}

return (new GeneratedApplicationLoader())->load(dirname(__DIR__), 'Portal Ciudadano JAS');
