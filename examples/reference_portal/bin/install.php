<?php

declare(strict_types=1);

use JAS\ReferencePortal\PortalKernel;

$jasRoot = realpath((string) getenv('JAS_ROOT'));
if ($jasRoot === false || !is_file($jasRoot . '/app/bootstrap.php')) throw new RuntimeException('JAS_ROOT is invalid');
require_once $jasRoot . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/PortalKernel.php';

$masterKey = (string) getenv('PORTAL_MASTER_KEY');
$pepper = (string) getenv('PORTAL_IDENTITY_PEPPER');
$adminPassword = (string) getenv('PORTAL_ADMIN_PASSWORD');
if (strlen($masterKey) < 32 || strlen($pepper) < 32 || strlen($adminPassword) < 12) {
    throw new RuntimeException('Required portal secrets are missing or invalid');
}
(new PortalKernel(dirname(__DIR__) . '/runtime', $masterKey, $pepper))->bootstrap($adminPassword);
echo "JAS PORTAL INSTALL: PASS\n";
