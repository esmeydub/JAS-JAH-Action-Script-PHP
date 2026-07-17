<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/examples/reference_portal/app/PortalKernel.php';

use JAS\ReferencePortal\PortalKernel;
use Jah\JAS\Definition\CompatibilityChecker;
use Jah\JAS\Diagnostics\DiagnosticCode;
use Jah\JAS\Diagnostics\DiagnosticException;
use Jah\JAS\Security\KeyRing;
use Jah\JAS\Tooling\ProjectAnalyzer;

$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path)) { if (is_file($path)) unlink($path); return; }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) $remove($path . '/' . $item);
    rmdir($path);
};
$expect = static function (callable $operation, string $message): void {
    try { $operation(); } catch (Throwable $error) {
        if ($error->getMessage() === $message) return;
        throw $error;
    }
    throw new RuntimeException('expected_error:' . $message);
};

$root = sys_get_temp_dir() . '/jas_reference_portal_' . bin2hex(random_bytes(5));
$master = random_bytes(32);
$pepper = bin2hex(random_bytes(32));
$adminPassword = 'Admin-Portal-2026!VeryStrong';
$kernel = new PortalKernel($root . '/primary', $master, $pepper);
$kernel->bootstrap($adminPassword);

$login = static function (PortalKernel $portal, string $id, string $username, string $password): string {
    $result = $portal->anonymousRuntime()->execute('identidad.authenticate', [
        'id' => $id, 'username' => $username, 'password' => $password,
        'device_id' => 'DEVICE-' . $id, 'device_label' => 'Prueba Fase 10',
    ], 'LOGIN-' . $id);
    return (string) $result['result']['token'];
};

$adminToken = $login($kernel, 'LOGIN-ADMIN', 'admin', $adminPassword);
$admin = $kernel->runtimeForToken($adminToken);
$users = [
    ['USER-CITIZEN', 'citizen', 'Ciudadana Uno', 'Citizen-Portal-2026!Strong', 'citizen'],
    ['USER-MODERATOR', 'moderator', 'Moderadora Uno', 'Moderator-Portal-2026!Strong', 'moderator'],
    ['USER-AUDITOR', 'auditor', 'Auditora Uno', 'Auditor-Portal-2026!Strong', 'auditor'],
];
foreach ($users as [$id, $username, $name, $password, $role]) {
    $admin->execute('usuario.register', [
        'id' => $id, 'username' => $username, 'display_name' => $name,
        'password' => $password, 'role' => $role,
    ], 'REGISTER-' . $id);
}

$citizenToken = $login($kernel, 'LOGIN-CITIZEN', 'citizen', $users[0][3]);
$moderatorToken = $login($kernel, 'LOGIN-MODERATOR', 'moderator', $users[1][3]);
$auditorToken = $login($kernel, 'LOGIN-AUDITOR', 'auditor', $users[2][3]);
$citizen = $kernel->runtimeForToken($citizenToken);
$moderator = $kernel->runtimeForToken($moderatorToken);
$auditor = $kernel->runtimeForToken($auditorToken);

$published = $citizen->execute('publicacion.publish', ['id' => 'POST-ONE', 'content' => 'Aviso ciudadano verificado.'], 'PUBLISH-ONE');
$replayed = $citizen->execute('publicacion.publish', ['id' => 'POST-ONE', 'content' => 'Aviso ciudadano verificado.'], 'PUBLISH-ONE');
if (($published['result'] ?? null) !== ($replayed['result'] ?? null) || ($replayed['replayed'] ?? false) !== true) {
    throw new RuntimeException('portal_idempotency_failed');
}
$moderator->execute('moderacion.review', ['id' => 'POST-ONE', 'decision' => 'approved'], 'REVIEW-ONE');
$feed = $citizen->execute('feed.read', ['id' => 'FEED-ONE', 'limit' => 25]);
if (($feed['result']['posts'][0]['id'] ?? null) !== 'POST-ONE') throw new RuntimeException('portal_feed_failed');
$citizen->execute('mensaje.send', [
    'id' => 'MESSAGE-ONE', 'recipient_id' => 'USER-MODERATOR', 'body' => 'Mensaje institucional privado.',
], 'MESSAGE-ONE');
$notices = $moderator->execute('notificacion.list', ['id' => 'NOTICE-LIST', 'limit' => 25]);
if (($notices['result']['notifications'][0]['kind'] ?? null) !== 'message.received') throw new RuntimeException('portal_notification_failed');
$audit = $auditor->execute('auditoria.verify', ['id' => 'AUDIT-ONE']);
if (($audit['result']['valid'] ?? false) !== true) throw new RuntimeException('portal_audit_failed');

try {
    $citizen->execute('moderacion.review', ['id' => 'POST-ONE', 'decision' => 'rejected']);
    throw new RuntimeException('portal_authorization_not_enforced');
} catch (DiagnosticException $error) {
    if ($error->diagnostic()->code !== DiagnosticCode::CAPABILITY_MISSING) throw $error;
}
$expect(fn() => $admin->execute('usuario.register', [
    'id' => 'USER-ROOT', 'username' => 'rootuser', 'display_name' => 'No permitido',
    'password' => 'Root-Portal-2026!Strong', 'role' => 'admin',
], 'REGISTER-ROOT'), 'portal_role_not_allowed');

// Carga representativa: el camino gobernado mantiene contratos, auditoría e índices.
for ($number = 2; $number <= 80; $number++) {
    $id = 'POST-' . $number;
    $citizen->execute('publicacion.publish', ['id' => $id, 'content' => 'Publicación de carga ' . $number], 'PUBLISH-' . $number);
    $moderator->execute('moderacion.review', ['id' => $id, 'decision' => 'approved'], 'REVIEW-' . $number);
}
$loadedFeed = $citizen->execute('feed.read', ['id' => 'FEED-LOAD', 'limit' => 100]);
if (count($loadedFeed['result']['posts']) !== 80) throw new RuntimeException('portal_load_path_failed');
$stats = $kernel->queueStats();
if (($stats['feed']['total'] ?? 0) !== 80 || ($stats['moderation']['total'] ?? 0) !== 80
    || ($stats['notification']['total'] ?? 0) < 81) {
    throw new RuntimeException('portal_queue_isolation_failed');
}

// Backup cifrado, verificación y restauración funcional de DataCore completo.
$backupKey = random_bytes(32);
$backup = $kernel->backupService($root . '/backups', new KeyRing(['phase10' => $backupKey], 'phase10'));
$backup->create('portal-phase10');
if (!$backup->verify('portal-phase10')) throw new RuntimeException('portal_backup_verify_failed');
$backup->restore('portal-phase10', $root . '/restored/datacore');
$restored = new PortalKernel($root . '/restored', $master, $pepper);
$restoredFeed = $restored->runtimeForToken($citizenToken)->execute('feed.read', ['id' => 'FEED-RESTORED', 'limit' => 100]);
if (count($restoredFeed['result']['posts']) !== 80) throw new RuntimeException('portal_restore_failed');

// Una adición opcional conserva compatibilidad de actualización.
$current = $kernel->describe();
$next = $current;
$next['types']['PostView']['fields']['summary?'] = 'string';
$compatibility = (new CompatibilityChecker())->compare($current, $next);
if (!$compatibility->compatible()) throw new RuntimeException('portal_upgrade_compatibility_failed');

$analysis = (new ProjectAnalyzer())->analyze(dirname(__DIR__) . '/examples/reference_portal');
if (($analysis['ok'] ?? false) !== true) throw new RuntimeException('portal_analysis_failed');

$remove($root);
echo "JAS REFERENCE PORTAL: PASS\n";
