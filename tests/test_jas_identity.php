<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/support.php';

use Jah\DataCore\DataCoreDatabase;
use Jah\DataCore\DataCoreTurbo;
use Jah\JAS\Persistence\AuditJournal;
use Jah\JAS\Security\DualControlStore;
use Jah\JAS\Security\InstitutionalIdentityService;
use Jah\JAS\Security\Totp;
use Jah\JAS\Type\TypeRegistry;
use Jah\JAS\Security\RolePolicy;
use Jah\JAS\Web\AuthMiddleware;
use Jah\JAS\Web\Request;
use Jah\JAS\Web\Response;

$throws = static function (callable $operation, string $expected): void {
    try {
        $operation();
    } catch (Throwable $error) {
        if ($error->getMessage() === $expected) return;
        throw $error;
    }
    throw new RuntimeException('Expected ' . $expected);
};

$base = sys_get_temp_dir() . '/jas_institutional_identity_' . bin2hex(random_bytes(5));
$now = 1_800_000_000;
$types = new TypeRegistry();
InstitutionalIdentityService::defineTypes($types);
$storage = new DataCoreTurbo($base . '/storage', 1);
$database = new DataCoreDatabase($storage, $types, $base . '/runtime', random_bytes(32));
InstitutionalIdentityService::configureDatabase($database);
$audit = new AuditJournal($base . '/audit');
$dual = new DualControlStore($base . '/dual');
$identity = new InstitutionalIdentityService(
    $database,
    $audit,
    $dual,
    random_bytes(32),
    static function () use (&$now): int { return $now; },
);

try {
    $identity->createUser('SYSTEM', 'USER-ADMIN', 'admin', 'Administradora', 'Clave-Administradora-2026');
    $identity->createUser('SYSTEM', 'USER-APPROVER', 'approver', 'Supervisor', 'Clave-Supervisor-2026');
    $identity->createUser('SYSTEM', 'USER-TARGET', 'target', 'Persona temporal', 'Clave-Temporal-2026');
    $identity->createUser('SYSTEM', 'USER-LOCKED', 'locked', 'Persona bloqueada', 'Clave-Bloqueada-2026');

    $identity->defineRole('SYSTEM', 'critical-requester', [
        'records.delete.request', 'records.read',
    ], ['critical-approver']);
    $identity->defineRole(
        'SYSTEM', 'critical-approver', ['records.delete.approve'], ['critical-requester'],
    );
    $identity->defineRole('SYSTEM', 'operator', ['records.write'], ['auditor']);
    $identity->defineRole('SYSTEM', 'auditor', ['records.audit'], ['operator']);
    $identity->defineRole('SYSTEM', 'temporary-reader', ['temporary.read']);
    $identity->defineRole('SYSTEM', 'delegable', ['delegated.work']);

    $identity->assignRole('SYSTEM', 'USER-ADMIN', 'critical-requester');
    $identity->assignRole('SYSTEM', 'USER-ADMIN', 'operator');
    $identity->assignRole('SYSTEM', 'USER-ADMIN', 'delegable');
    $identity->assignRole('SYSTEM', 'USER-APPROVER', 'critical-approver');
    $throws(
        fn() => $identity->assignRole('SYSTEM', 'USER-ADMIN', 'auditor'),
        'identity_role_separation_of_duties',
    );

    $adminLogin = $identity->login(
        'admin', 'Clave-Administradora-2026', 'DEVICE-ADMIN', 'Laptop institucional', 3600,
    );
    $adminToken = (string) ($adminLogin['token'] ?? '');
    if ($adminLogin['status'] !== 'authenticated'
        || !$identity->allows($adminToken, 'records.delete.request')
        || $identity->allows($adminToken, 'records.delete.approve')) {
        throw new RuntimeException('identity_password_login_permissions_failed');
    }
    $middleware = new AuthMiddleware(
        $identity,
        new RolePolicy([]),
        ['GET /records' => 'records.read', 'DELETE /records' => 'records.delete.approve'],
    );
    $allowedResponse = $middleware->process(
        new Request('GET', '/records', headers: ['authorization' => 'Bearer ' . $adminToken]),
        static fn(Request $request): Response => new Response('ok', 200),
    );
    $deniedResponse = $middleware->process(
        new Request('DELETE', '/records', headers: ['authorization' => 'Bearer ' . $adminToken]),
        static fn(Request $request): Response => new Response('unsafe', 200),
    );
    if ($allowedResponse->status !== 200 || $deniedResponse->status !== 403) {
        throw new RuntimeException('identity_dynamic_web_authorization_failed');
    }
    $identity->replaceRolePermissions('SYSTEM', 'critical-requester', [
        'records.delete.request', 'records.read', 'records.export',
    ]);
    if (!$identity->allows($adminToken, 'records.export')) {
        throw new RuntimeException('identity_dynamic_permission_change_failed');
    }

    $sessions = $identity->sessions($adminToken);
    if (count($sessions) !== 1 || ($sessions[0]['current'] ?? null) !== true) {
        throw new RuntimeException('identity_device_visibility_failed');
    }

    $throws(
        fn() => $identity->previewTotpSecret('USER-ADMIN', 'clave-incorrecta'),
        'identity_credentials_invalid',
    );
    $totpSecret = $identity->previewTotpSecret('USER-ADMIN', 'Clave-Administradora-2026');
    $throws(
        fn() => $identity->confirmTotp('USER-ADMIN', '000000'),
        'identity_mfa_confirmation_invalid',
    );
    $recoveryCodes = $identity->confirmTotp('USER-ADMIN', Totp::code($totpSecret, $now));
    if (count($recoveryCodes) !== 10) throw new RuntimeException('identity_recovery_codes_failed');
    if ($identity->identity($adminToken) !== null) {
        throw new RuntimeException('identity_pre_mfa_session_not_revoked');
    }

    $mfaLogin = $identity->login(
        'admin', 'Clave-Administradora-2026', 'DEVICE-MFA', 'Teléfono seguro', 600,
    );
    if (($mfaLogin['status'] ?? null) !== 'mfa_required' || isset($mfaLogin['token'])) {
        throw new RuntimeException('identity_mfa_not_enforced');
    }
    $challenge = (string) $mfaLogin['challenge'];
    $throws(fn() => $identity->completeMfa($challenge, '111111'), 'identity_mfa_code_invalid');
    $mfaToken = $identity->completeMfa($challenge, Totp::code($totpSecret, $now));
    $throws(fn() => $identity->completeMfa($challenge, Totp::code($totpSecret, $now)), 'identity_mfa_challenge_invalid');
    if (($identity->identity($mfaToken)['mfa_verified'] ?? null) !== true) {
        throw new RuntimeException('identity_mfa_session_not_verified');
    }

    $recoveryLogin = $identity->login(
        'admin', 'Clave-Administradora-2026', 'DEVICE-RECOVERY', 'Equipo de recuperación', 600,
    );
    $recoveryToken = $identity->completeMfa((string) $recoveryLogin['challenge'], $recoveryCodes[0]);
    if ($identity->identity($recoveryToken) === null) throw new RuntimeException('identity_recovery_login_failed');
    $reusedLogin = $identity->login(
        'admin', 'Clave-Administradora-2026', 'DEVICE-REUSE', 'Equipo de prueba', 600,
    );
    $throws(
        fn() => $identity->completeMfa((string) $reusedLogin['challenge'], $recoveryCodes[0]),
        'identity_mfa_code_invalid',
    );

    $targetSecret = $identity->previewTotpSecret('USER-TARGET', 'Clave-Temporal-2026');
    $now += 601;
    $throws(
        fn() => $identity->confirmTotp('USER-TARGET', Totp::code($targetSecret, $now)),
        'identity_mfa_enrollment_expired',
    );

    $targetLogin = $identity->login(
        'target', 'Clave-Temporal-2026', 'DEVICE-TARGET', 'Equipo temporal', 3600,
    );
    $targetToken = (string) $targetLogin['token'];
    $assignment = $identity->assignRole('SYSTEM', 'USER-TARGET', 'temporary-reader', $now + 60);
    if (!$identity->allows($targetToken, 'temporary.read')) throw new RuntimeException('identity_temporary_role_missing');
    $now += 60;
    if ($identity->allows($targetToken, 'temporary.read')) throw new RuntimeException('identity_temporary_role_not_expired');
    $identity->revokeAssignment('SYSTEM', $assignment);

    $freshAdminLogin = $identity->login(
        'admin', 'Clave-Administradora-2026', 'DEVICE-FRESH-MFA', 'Estación crítica', 3600,
    );
    $freshAdminToken = $identity->completeMfa(
        (string) $freshAdminLogin['challenge'], Totp::code($totpSecret, $now),
    );
    $delegation = $identity->delegateRole($freshAdminToken, 'USER-TARGET', 'delegable', $now + 120);
    if (!$identity->allows($targetToken, 'delegated.work')) throw new RuntimeException('identity_delegation_failed');
    $identity->revokeAssignment('USER-ADMIN', $delegation);
    if ($identity->allows($targetToken, 'delegated.work')) throw new RuntimeException('identity_delegation_revocation_failed');
    $throws(
        fn() => $identity->delegateRole($targetToken, 'USER-APPROVER', 'delegable', $now + 120),
        'identity_delegation_role_not_held',
    );

    $approverSecret = $identity->previewTotpSecret('USER-APPROVER', 'Clave-Supervisor-2026');
    $identity->confirmTotp('USER-APPROVER', Totp::code($approverSecret, $now));
    $approverLogin = $identity->login(
        'approver', 'Clave-Supervisor-2026', 'DEVICE-APPROVER', 'Estación de aprobación', 3600,
    );
    $approverToken = $identity->completeMfa(
        (string) $approverLogin['challenge'], Totp::code($approverSecret, $now),
    );
    $fingerprint = hash('sha256', 'EXP-CRITICAL-1');
    $approval = $identity->requestCriticalAction(
        $freshAdminToken, 'records.delete', 'DELETE-CRITICAL-1', $fingerprint,
    );
    $throws(
        fn() => $identity->approveCriticalAction($freshAdminToken, $approval, 'records.delete'),
        'identity_permission_denied',
    );
    $identity->approveCriticalAction($approverToken, $approval, 'records.delete');
    $consumed = $identity->consumeCriticalAction(
        $approval, 'records.delete', 'DELETE-CRITICAL-1', $fingerprint,
    );
    if (($consumed['requester_id'] ?? null) !== 'USER-ADMIN') {
        throw new RuntimeException('identity_dual_control_failed');
    }
    $throws(
        fn() => $identity->consumeCriticalAction(
            $approval, 'records.delete', 'DELETE-CRITICAL-1', $fingerprint,
        ),
        'dual_control_not_approved',
    );

    $credential = $identity->issueServiceCredential('USER-ADMIN', 'SERVICE-REPORTS', $now + 300);
    if ($identity->verifyServiceCredential($credential['id'], $credential['secret']) !== 'SERVICE-REPORTS') {
        throw new RuntimeException('identity_service_credential_failed');
    }
    $rotated = $identity->rotateServiceCredential('USER-ADMIN', $credential['id']);
    if ($identity->verifyServiceCredential($credential['id'], $credential['secret']) !== null
        || $identity->verifyServiceCredential($rotated['id'], $rotated['secret']) !== 'SERVICE-REPORTS') {
        throw new RuntimeException('identity_service_credential_rotation_failed');
    }
    $now += 300;
    if ($identity->verifyServiceCredential($rotated['id'], $rotated['secret']) !== null) {
        throw new RuntimeException('identity_service_credential_not_expired');
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $throws(
            fn() => $identity->login('locked', 'incorrecta-larga', 'DEVICE-LOCK', 'Equipo bloqueado'),
            'identity_credentials_invalid',
        );
    }
    $throws(
        fn() => $identity->login('locked', 'Clave-Bloqueada-2026', 'DEVICE-LOCK', 'Equipo bloqueado'),
        'identity_login_locked',
    );
    $now += 301;
    $unlocked = $identity->login(
        'locked', 'Clave-Bloqueada-2026', 'DEVICE-LOCK', 'Equipo bloqueado', 60,
    );
    if (($unlocked['status'] ?? null) !== 'authenticated') throw new RuntimeException('identity_unlock_failed');

    $currentSessionId = (string) ($identity->identity($freshAdminToken)['session_id'] ?? '');
    $identity->revokeSession($freshAdminToken, $currentSessionId);
    if ($identity->identity($freshAdminToken) !== null) throw new RuntimeException('identity_revoked_session_active');

    $rawUser = $storage->find('identity_users', 'USER-ADMIN');
    $rawRole = $storage->find('identity_roles', 'critical-requester');
    if (!is_array($rawUser['password_hash'] ?? null)
        || !is_array($rawUser['display_name'] ?? null)
        || !is_array($rawRole['permissions'] ?? null)) {
        throw new RuntimeException('identity_sensitive_data_not_encrypted');
    }
    if (!$audit->verify()) throw new RuntimeException('identity_audit_invalid');

    echo "JAS INSTITUTIONAL IDENTITY: PASS\n";
} finally {
    jas_test_remove_tree($base);
}
