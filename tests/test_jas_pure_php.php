<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\Security\SalkGuard;

$rootGuard = new SalkGuard(dirname(__DIR__));
$rootResult = $rootGuard->checkPackageVectors();
if (!$rootResult['ok'] || $rootResult['json_detected'] || $rootResult['script_detected'] || $rootResult['node_detected']) {
    throw new RuntimeException('jas_repository_not_pure_php');
}

$sandbox = sys_get_temp_dir() . '/jas_pure_php_' . bin2hex(random_bytes(6));
mkdir($sandbox, 0700, true);
$guard = new SalkGuard($sandbox);
$suffix = chr(106) . chr(115) . chr(111) . chr(110);
file_put_contents($sandbox . '/foreign.' . $suffix, '{}');
$rejected = $guard->checkPackageVectors();
if ($rejected['ok'] || !$rejected['json_detected']) throw new RuntimeException('jas_json_artifact_not_rejected');
unlink($sandbox . '/foreign.' . $suffix);

file_put_contents($sandbox . '/foreign.' . chr(106) . chr(115), 'throw new Error();');
$scriptRejected = $guard->checkPackageVectors();
if ($scriptRejected['ok']) throw new RuntimeException('jas_script_artifact_not_rejected');
unlink($sandbox . '/foreign.' . chr(106) . chr(115));

mkdir($sandbox . '/vendor', 0700);
$dependencyRejected = $guard->checkPackageVectors();
if ($dependencyRejected['ok'] || !isset($dependencyRejected['files']['vendor'])) {
    throw new RuntimeException('jas_composer_dependency_not_rejected');
}

echo "JAS PURE PHP: PASS\n";
