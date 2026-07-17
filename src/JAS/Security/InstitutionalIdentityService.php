<?php

declare(strict_types=1);

namespace Jah\JAS\Security;

use Jah\DataCore\DataCoreDatabase;
use Jah\DataCore\PhpSerializer;
use Jah\JAS\Persistence\AuditJournal;
use Jah\JAS\Type\TypeRegistry;
use RuntimeException;

final class InstitutionalIdentityService implements AuthorizationProvider
{
    private const MAX_SESSION_TTL = 86_400;
    private const MAX_DELEGATION_TTL = 2_592_000;
    /** @var callable():int|float */
    private mixed $clock;

    public function __construct(
        private readonly DataCoreDatabase $database,
        private readonly AuditJournal $audit,
        private readonly DualControlStore $dualControl,
        private readonly string $pepper,
        ?callable $clock = null,
    ) {
        if (strlen($pepper) < 32) throw new RuntimeException('identity_pepper_invalid');
        $this->clock = $clock ?? static fn(): int => time();
    }

    public static function defineTypes(TypeRegistry $types): void
    {
        $types->define('InstitutionalUser', [
            'id' => 'identifier', 'username' => 'non-empty-string',
            'display_name' => 'non-empty-string', 'password_hash' => 'non-empty-string',
            'active' => 'bool', 'failed_attempts' => 'non-negative-int',
            'locked_until' => 'non-negative-int', 'mfa_enabled' => 'bool',
            'totp_secret?' => 'non-empty-string', 'recovery_hashes' => 'string[]',
            'created_at' => 'positive-int', 'updated_at' => 'positive-int',
        ]);
        $types->define('InstitutionalRole', [
            'id' => 'identifier', 'permissions' => 'string[]',
            'incompatible_roles' => 'string[]', 'active' => 'bool',
            'created_at' => 'positive-int', 'updated_at' => 'positive-int',
        ]);
        $types->define('InstitutionalRoleAssignment', [
            'id' => 'identifier', 'user_lookup' => 'non-empty-string',
            'user_id' => 'identifier', 'role_id' => 'identifier',
            'starts_at' => 'positive-int', 'expires_at?' => 'positive-int',
            'delegated_by?' => 'identifier', 'revoked' => 'bool',
            'created_at' => 'positive-int',
        ]);
        $types->define('InstitutionalSession', [
            'id' => 'identifier', 'user_lookup' => 'non-empty-string',
            'user_id' => 'identifier', 'device_id' => 'identifier',
            'device_label' => 'non-empty-string', 'mfa_verified' => 'bool',
            'created_at' => 'positive-int', 'expires_at' => 'positive-int',
            'revoked_at?' => 'positive-int',
        ]);
        $types->define('InstitutionalMfaEnrollment', [
            'id' => 'identifier', 'user_id' => 'identifier',
            'secret' => 'non-empty-string', 'expires_at' => 'positive-int',
            'created_at' => 'positive-int',
        ]);
        $types->define('InstitutionalMfaChallenge', [
            'id' => 'identifier', 'user_id' => 'identifier',
            'device_id' => 'identifier', 'device_label' => 'non-empty-string',
            'session_ttl' => 'positive-int', 'expires_at' => 'positive-int',
            'created_at' => 'positive-int',
        ]);
        $types->define('InstitutionalServiceCredential', [
            'id' => 'identifier', 'service_id' => 'identifier',
            'secret_hash' => 'non-empty-string', 'version' => 'positive-int',
            'active' => 'bool', 'created_at' => 'positive-int',
            'rotated_at?' => 'positive-int', 'expires_at?' => 'positive-int',
        ]);
    }

    public static function configureDatabase(DataCoreDatabase $database): void
    {
        $database->collection('identity_users', 'InstitutionalUser')
            ->uniqueIndex('identity_users', 'username_unique', ['username'])
            ->encryptFields('identity_users', [
                'display_name', 'password_hash', 'totp_secret', 'recovery_hashes',
            ])
            ->collection('identity_roles', 'InstitutionalRole')
            ->encryptFields('identity_roles', ['permissions', 'incompatible_roles'])
            ->collection('identity_assignments', 'InstitutionalRoleAssignment')
            ->index('identity_assignments', 'assignments_by_user', ['user_lookup'])
            ->encryptFields('identity_assignments', ['user_id', 'role_id', 'delegated_by'])
            ->collection('identity_sessions', 'InstitutionalSession')
            ->index('identity_sessions', 'sessions_by_user', ['user_lookup'])
            ->encryptFields('identity_sessions', ['user_id', 'device_id', 'device_label'])
            ->collection('identity_mfa_enrollments', 'InstitutionalMfaEnrollment')
            ->encryptFields('identity_mfa_enrollments', ['user_id', 'secret'])
            ->collection('identity_mfa_challenges', 'InstitutionalMfaChallenge')
            ->encryptFields('identity_mfa_challenges', ['user_id', 'device_id', 'device_label'])
            ->collection('identity_service_credentials', 'InstitutionalServiceCredential')
            ->encryptFields('identity_service_credentials', ['service_id', 'secret_hash']);
    }

    public function createUser(
        string $actorId,
        string $userId,
        string $username,
        string $displayName,
        string $password,
    ): void {
        $this->identifier($actorId, 'identity_actor_invalid');
        $this->identifier($userId, 'identity_user_invalid');
        $username = strtolower(trim($username));
        if (!preg_match('/^[a-z0-9_.-]{3,64}$/', $username) || trim($displayName) === '') {
            throw new RuntimeException('identity_user_invalid');
        }
        $this->assertPassword($password);
        $now = $this->now();
        $this->database->insert('identity_users', [
            'id' => $userId, 'username' => $username, 'display_name' => $displayName,
            'password_hash' => $this->passwordHash($password), 'active' => true,
            'failed_attempts' => 0, 'locked_until' => 0, 'mfa_enabled' => false,
            'recovery_hashes' => [], 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->record($actorId, 'identity.user.create', $userId, ['username' => $username]);
    }

    public function defineRole(
        string $actorId,
        string $roleId,
        array $permissions,
        array $incompatibleRoles = [],
    ): void {
        $this->identifier($actorId, 'identity_actor_invalid');
        $this->roleId($roleId);
        $permissions = $this->permissions($permissions);
        $incompatibleRoles = array_values(array_unique(array_map(function (mixed $role): string {
            $this->roleId((string) $role);
            return (string) $role;
        }, $incompatibleRoles)));
        if (in_array($roleId, $incompatibleRoles, true)) {
            throw new RuntimeException('identity_role_self_incompatible');
        }
        $now = $this->now();
        $this->database->insert('identity_roles', [
            'id' => $roleId, 'permissions' => $permissions,
            'incompatible_roles' => $incompatibleRoles, 'active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->record($actorId, 'identity.role.define', $roleId, [
            'permissions' => $permissions, 'incompatible' => $incompatibleRoles,
        ]);
    }

    public function replaceRolePermissions(string $actorId, string $roleId, array $permissions): void
    {
        $role = $this->role($roleId);
        $permissions = $this->permissions($permissions);
        $document = $this->plain($role);
        $document['permissions'] = $permissions;
        $document['updated_at'] = $this->now();
        $this->database->update('identity_roles', $roleId, $document, (int) $role['_version']);
        $this->record($actorId, 'identity.role.permissions.replace', $roleId, $permissions);
    }

    public function assignRole(
        string $actorId,
        string $userId,
        string $roleId,
        ?int $expiresAt = null,
        ?string $delegatedBy = null,
    ): string {
        $this->user($userId);
        $targetRole = $this->role($roleId);
        if (($targetRole['active'] ?? false) !== true) throw new RuntimeException('identity_role_inactive');
        $now = $this->now();
        if ($expiresAt !== null && $expiresAt <= $now) throw new RuntimeException('identity_role_expiration_invalid');
        $activeRoles = $this->activeRolesForUser($userId);
        if (isset($activeRoles[$roleId])) throw new RuntimeException('identity_role_already_assigned');
        foreach ($activeRoles as $existingId => $existing) {
            if (in_array($existingId, (array) $targetRole['incompatible_roles'], true)
                || in_array($roleId, (array) $existing['incompatible_roles'], true)) {
                throw new RuntimeException('identity_role_separation_of_duties');
            }
        }
        $id = 'ASSIGN-' . bin2hex(random_bytes(12));
        $document = [
            'id' => $id, 'user_lookup' => $this->lookup($userId), 'user_id' => $userId,
            'role_id' => $roleId, 'starts_at' => $now, 'revoked' => false, 'created_at' => $now,
        ];
        if ($expiresAt !== null) $document['expires_at'] = $expiresAt;
        if ($delegatedBy !== null) $document['delegated_by'] = $delegatedBy;
        $this->database->insert('identity_assignments', $document);
        $this->record($actorId, 'identity.role.assign', $id, [
            'user' => $userId, 'role' => $roleId, 'expires_at' => $expiresAt,
        ]);
        return $id;
    }

    public function revokeAssignment(string $actorId, string $assignmentId): void
    {
        $assignment = $this->database->find('identity_assignments', $assignmentId)
            ?? throw new RuntimeException('identity_assignment_not_found');
        $document = $this->plain($assignment);
        $document['revoked'] = true;
        $this->database->update(
            'identity_assignments', $assignmentId, $document, (int) $assignment['_version'],
        );
        $this->record($actorId, 'identity.role.revoke', $assignmentId, []);
    }

    public function delegateRole(
        string $sessionToken,
        string $targetUserId,
        string $roleId,
        int $expiresAt,
    ): string {
        $identity = $this->identity($sessionToken) ?? throw new RuntimeException('identity_session_invalid');
        $now = $this->now();
        if ($expiresAt <= $now || $expiresAt > $now + self::MAX_DELEGATION_TTL) {
            throw new RuntimeException('identity_delegation_expiration_invalid');
        }
        if (!in_array($roleId, $identity['roles'], true)) {
            throw new RuntimeException('identity_delegation_role_not_held');
        }
        return $this->assignRole(
            (string) $identity['id'], $targetUserId, $roleId, $expiresAt, (string) $identity['id'],
        );
    }

    /** @return array{status:string,token?:string,challenge?:string} */
    public function login(
        string $username,
        string $password,
        string $deviceId,
        string $deviceLabel,
        int $ttlSeconds = 3600,
    ): array {
        $this->sessionParameters($deviceId, $deviceLabel, $ttlSeconds);
        $username = strtolower(trim($username));
        $matches = $this->database->findByIndex(
            'identity_users', 'username_unique', ['username' => $username], 1,
        );
        $user = $matches[0] ?? null;
        if (!is_array($user) || ($user['active'] ?? false) !== true) {
            $this->record('anonymous', 'identity.login', $this->requestId(), ['username' => $username], false, 'identity_credentials_invalid');
            throw new RuntimeException('identity_credentials_invalid');
        }
        $now = $this->now();
        if ((int) $user['locked_until'] > $now) throw new RuntimeException('identity_login_locked');
        if (!password_verify($password, (string) $user['password_hash'])) {
            $document = $this->plain($user);
            $document['failed_attempts'] = (int) $document['failed_attempts'] + 1;
            if ($document['failed_attempts'] >= 5) $document['locked_until'] = $now + 300;
            $document['updated_at'] = $now;
            $this->database->update('identity_users', (string) $user['id'], $document, (int) $user['_version']);
            $this->record((string) $user['id'], 'identity.login', $this->requestId(), [], false, 'identity_credentials_invalid');
            throw new RuntimeException('identity_credentials_invalid');
        }
        if ((int) $user['failed_attempts'] !== 0 || (int) $user['locked_until'] !== 0) {
            $document = $this->plain($user);
            $document['failed_attempts'] = 0;
            $document['locked_until'] = 0;
            $document['updated_at'] = $now;
            $user = $this->database->update('identity_users', (string) $user['id'], $document, (int) $user['_version']);
        }
        if (($user['mfa_enabled'] ?? false) === true) {
            $challenge = bin2hex(random_bytes(32));
            $this->database->insert('identity_mfa_challenges', [
                'id' => $this->challengeId($challenge), 'user_id' => $user['id'],
                'device_id' => $deviceId, 'device_label' => $deviceLabel,
                'session_ttl' => $ttlSeconds, 'expires_at' => $now + 300, 'created_at' => $now,
            ]);
            return ['status' => 'mfa_required', 'challenge' => $challenge];
        }
        return ['status' => 'authenticated', 'token' => $this->createSession(
            (string) $user['id'], $deviceId, $deviceLabel, $ttlSeconds, false,
        )];
    }

    public function previewTotpSecret(string $userId, string $currentPassword): string
    {
        $user = $this->user($userId);
        if (!password_verify($currentPassword, (string) $user['password_hash'])) {
            throw new RuntimeException('identity_credentials_invalid');
        }
        if (($user['mfa_enabled'] ?? false) === true) throw new RuntimeException('identity_mfa_already_enabled');
        $existing = $this->database->find('identity_mfa_enrollments', 'ENROLL-' . $this->lookup($userId));
        if (is_array($existing) && (int) $existing['expires_at'] > $this->now()) return (string) $existing['secret'];
        if (is_array($existing)) {
            $this->database->delete('identity_mfa_enrollments', (string) $existing['id'], (int) $existing['_version']);
        }
        $secret = Totp::secret();
        $now = $this->now();
        $this->database->insert('identity_mfa_enrollments', [
            'id' => 'ENROLL-' . $this->lookup($userId), 'user_id' => $userId,
            'secret' => $secret, 'expires_at' => $now + 600, 'created_at' => $now,
        ]);
        return $secret;
    }

    /** @return list<string> */
    public function confirmTotp(string $userId, string $code): array
    {
        $id = 'ENROLL-' . $this->lookup($userId);
        $enrollment = $this->database->find('identity_mfa_enrollments', $id)
            ?? throw new RuntimeException('identity_mfa_enrollment_not_found');
        if ((int) $enrollment['expires_at'] <= $this->now()) {
            $this->database->delete('identity_mfa_enrollments', $id, (int) $enrollment['_version']);
            throw new RuntimeException('identity_mfa_enrollment_expired');
        }
        if (!Totp::verify((string) $enrollment['secret'], $code, $this->now())) {
            throw new RuntimeException('identity_mfa_confirmation_invalid');
        }
        $result = $this->persistTotp($this->user($userId), (string) $enrollment['secret']);
        $this->database->delete('identity_mfa_enrollments', $id, (int) $enrollment['_version']);
        return $result['recovery_codes'];
    }

    public function completeMfa(string $challengeToken, string $code): string
    {
        $id = $this->challengeId($challengeToken);
        $challenge = $this->database->find('identity_mfa_challenges', $id)
            ?? throw new RuntimeException('identity_mfa_challenge_invalid');
        if ((int) $challenge['expires_at'] <= $this->now()) {
            $this->database->delete('identity_mfa_challenges', $id, (int) $challenge['_version']);
            throw new RuntimeException('identity_mfa_challenge_expired');
        }
        $user = $this->user((string) $challenge['user_id']);
        $valid = Totp::verify((string) ($user['totp_secret'] ?? ''), $code, $this->now());
        if (!$valid) $valid = $this->consumeRecoveryCode($user, $code);
        if (!$valid) throw new RuntimeException('identity_mfa_code_invalid');
        $this->database->delete('identity_mfa_challenges', $id, (int) $challenge['_version']);
        return $this->createSession(
            (string) $user['id'], (string) $challenge['device_id'],
            (string) $challenge['device_label'], (int) $challenge['session_ttl'], true,
        );
    }

    public function identity(string $token): ?array
    {
        if ($token === '') return null;
        $session = $this->database->find('identity_sessions', $this->sessionId($token));
        if (!is_array($session) || isset($session['revoked_at']) || (int) $session['expires_at'] <= $this->now()) return null;
        $user = $this->database->find('identity_users', (string) $session['user_id']);
        if (!is_array($user) || ($user['active'] ?? false) !== true) return null;
        $roles = $this->activeRolesForUser((string) $user['id']);
        $permissions = [];
        foreach ($roles as $role) $permissions = array_merge($permissions, (array) $role['permissions']);
        return [
            'id' => $user['id'], 'username' => $user['username'],
            'display_name' => $user['display_name'], 'roles' => array_keys($roles),
            'permissions' => array_values(array_unique($permissions)),
            'session_id' => $session['id'], 'device_id' => $session['device_id'],
            'mfa_verified' => $session['mfa_verified'], 'expires_at' => $session['expires_at'],
        ];
    }

    public function allows(string $token, string $permission): bool
    {
        $identity = $this->identity($token);
        if (!is_array($identity)) return false;
        foreach ($identity['permissions'] as $grant) {
            if ($grant === '*' || $grant === $permission
                || (str_ends_with($grant, '.*') && str_starts_with($permission, substr($grant, 0, -1)))) return true;
        }
        return false;
    }

    public function sessions(string $token): array
    {
        $identity = $this->identity($token) ?? throw new RuntimeException('identity_session_invalid');
        $rows = $this->database->findByIndex(
            'identity_sessions', 'sessions_by_user', ['user_lookup' => $this->lookup((string) $identity['id'])], 1_000,
        );
        return array_map(static fn(array $session): array => [
            'id' => $session['id'], 'device_id' => $session['device_id'],
            'device_label' => $session['device_label'], 'created_at' => $session['created_at'],
            'expires_at' => $session['expires_at'], 'revoked' => isset($session['revoked_at']),
            'current' => $session['id'] === $identity['session_id'],
        ], $rows);
    }

    public function revokeSession(string $token, string $sessionId): void
    {
        $identity = $this->identity($token) ?? throw new RuntimeException('identity_session_invalid');
        $session = $this->database->find('identity_sessions', $sessionId)
            ?? throw new RuntimeException('identity_session_not_found');
        if (!hash_equals((string) $session['user_id'], (string) $identity['id'])) {
            throw new RuntimeException('identity_session_owner_mismatch');
        }
        if (!isset($session['revoked_at'])) {
            $document = $this->plain($session);
            $document['revoked_at'] = $this->now();
            $this->database->update('identity_sessions', $sessionId, $document, (int) $session['_version']);
        }
        $this->record((string) $identity['id'], 'identity.session.revoke', $sessionId, []);
    }

    public function requestCriticalAction(string $token, string $action, string $requestId, string $fingerprint): string
    {
        $identity = $this->identity($token) ?? throw new RuntimeException('identity_session_invalid');
        if (($identity['mfa_verified'] ?? false) !== true) throw new RuntimeException('identity_mfa_required');
        if (!$this->allows($token, $action . '.request')) throw new RuntimeException('identity_permission_denied');
        return $this->dualControl->request($action, (string) $identity['id'], $requestId, $fingerprint);
    }

    public function approveCriticalAction(string $token, string $approvalId, string $action): array
    {
        $identity = $this->identity($token) ?? throw new RuntimeException('identity_session_invalid');
        if (($identity['mfa_verified'] ?? false) !== true) throw new RuntimeException('identity_mfa_required');
        if (!$this->allows($token, $action . '.approve')) throw new RuntimeException('identity_permission_denied');
        $approved = $this->dualControl->approve($approvalId, (string) $identity['id']);
        $this->record((string) $identity['id'], 'identity.critical.approve', $approvalId, ['action' => $action]);
        return $approved;
    }

    public function consumeCriticalAction(
        string $approvalId,
        string $action,
        string $requestId,
        string $fingerprint,
    ): array {
        return $this->dualControl->consume($approvalId, $action, $requestId, $fingerprint);
    }

    /** @return array{id:string,secret:string,version:int} */
    public function issueServiceCredential(
        string $actorId,
        string $serviceId,
        ?int $expiresAt = null,
    ): array {
        $this->identifier($serviceId, 'identity_service_invalid');
        $now = $this->now();
        if ($expiresAt !== null && $expiresAt <= $now) throw new RuntimeException('identity_credential_expiration_invalid');
        $id = 'CRED-' . bin2hex(random_bytes(12));
        $secret = bin2hex(random_bytes(32));
        $document = [
            'id' => $id, 'service_id' => $serviceId, 'secret_hash' => $this->secretHash($secret),
            'version' => 1, 'active' => true, 'created_at' => $now,
        ];
        if ($expiresAt !== null) $document['expires_at'] = $expiresAt;
        $this->database->insert('identity_service_credentials', $document);
        $this->record($actorId, 'identity.service_credential.issue', $id, ['service' => $serviceId]);
        return ['id' => $id, 'secret' => $secret, 'version' => 1];
    }

    public function rotateServiceCredential(string $actorId, string $credentialId): array
    {
        $credential = $this->database->find('identity_service_credentials', $credentialId)
            ?? throw new RuntimeException('identity_credential_not_found');
        $secret = bin2hex(random_bytes(32));
        $document = $this->plain($credential);
        $document['secret_hash'] = $this->secretHash($secret);
        $document['version'] = (int) $document['version'] + 1;
        $document['rotated_at'] = $this->now();
        $this->database->update(
            'identity_service_credentials', $credentialId, $document, (int) $credential['_version'],
        );
        $this->record($actorId, 'identity.service_credential.rotate', $credentialId, ['version' => $document['version']]);
        return ['id' => $credentialId, 'secret' => $secret, 'version' => $document['version']];
    }

    public function verifyServiceCredential(string $credentialId, string $secret): ?string
    {
        $credential = $this->database->find('identity_service_credentials', $credentialId);
        if (!is_array($credential) || ($credential['active'] ?? false) !== true
            || (isset($credential['expires_at']) && (int) $credential['expires_at'] <= $this->now())
            || !hash_equals((string) $credential['secret_hash'], $this->secretHash($secret))) return null;
        return (string) $credential['service_id'];
    }

    private function persistTotp(array $user, string $secret): array
    {
        $recoveryCodes = [];
        $hashes = [];
        for ($index = 0; $index < 10; $index++) {
            $raw = strtoupper(bin2hex(random_bytes(6)));
            $code = substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);
            $recoveryCodes[] = $code;
            $hashes[] = $this->passwordHash($code);
        }
        $document = $this->plain($user);
        $document['mfa_enabled'] = true;
        $document['totp_secret'] = $secret;
        $document['recovery_hashes'] = $hashes;
        $document['updated_at'] = $this->now();
        $this->database->update('identity_users', (string) $user['id'], $document, (int) $user['_version']);
        $this->revokeAllUserSessions((string) $user['id']);
        $this->record((string) $user['id'], 'identity.mfa.enable', (string) $user['id'], []);
        return ['secret' => $secret, 'recovery_codes' => $recoveryCodes];
    }

    private function consumeRecoveryCode(array $user, string $code): bool
    {
        foreach ((array) $user['recovery_hashes'] as $index => $hash) {
            if (!password_verify($code, (string) $hash)) continue;
            $document = $this->plain($user);
            unset($document['recovery_hashes'][$index]);
            $document['recovery_hashes'] = array_values($document['recovery_hashes']);
            $document['updated_at'] = $this->now();
            $this->database->update('identity_users', (string) $user['id'], $document, (int) $user['_version']);
            $this->record((string) $user['id'], 'identity.mfa.recovery.consume', $this->requestId(), []);
            return true;
        }
        return false;
    }

    private function createSession(
        string $userId,
        string $deviceId,
        string $deviceLabel,
        int $ttl,
        bool $mfaVerified,
    ): string {
        $token = bin2hex(random_bytes(32));
        $now = $this->now();
        $this->database->insert('identity_sessions', [
            'id' => $this->sessionId($token), 'user_lookup' => $this->lookup($userId),
            'user_id' => $userId, 'device_id' => $deviceId, 'device_label' => $deviceLabel,
            'mfa_verified' => $mfaVerified, 'created_at' => $now, 'expires_at' => $now + $ttl,
        ]);
        $this->record($userId, 'identity.session.create', $this->sessionId($token), ['device' => $deviceId]);
        return $token;
    }

    private function revokeAllUserSessions(string $userId): void
    {
        $sessions = $this->database->findByIndex(
            'identity_sessions', 'sessions_by_user', ['user_lookup' => $this->lookup($userId)], 10_000,
        );
        foreach ($sessions as $session) {
            if (isset($session['revoked_at'])) continue;
            $document = $this->plain($session);
            $document['revoked_at'] = $this->now();
            $this->database->update(
                'identity_sessions', (string) $session['id'], $document, (int) $session['_version'],
            );
        }
    }

    /** @return array<string,array> */
    private function activeRolesForUser(string $userId): array
    {
        $assignments = $this->database->findByIndex(
            'identity_assignments', 'assignments_by_user', ['user_lookup' => $this->lookup($userId)], 1_000,
        );
        $roles = [];
        $now = $this->now();
        foreach ($assignments as $assignment) {
            if (($assignment['revoked'] ?? true) === true || (int) $assignment['starts_at'] > $now
                || (isset($assignment['expires_at']) && (int) $assignment['expires_at'] <= $now)) continue;
            $role = $this->database->find('identity_roles', (string) $assignment['role_id']);
            if (is_array($role) && ($role['active'] ?? false) === true) $roles[(string) $role['id']] = $role;
        }
        return $roles;
    }

    private function user(string $userId): array
    {
        return $this->database->find('identity_users', $userId)
            ?? throw new RuntimeException('identity_user_not_found');
    }

    private function role(string $roleId): array
    {
        return $this->database->find('identity_roles', $roleId)
            ?? throw new RuntimeException('identity_role_not_found');
    }

    private function plain(array $document): array
    {
        foreach (['_version', '_created_at', '_updated_at', '_ts', '_integrity', '_integrity_key', '_transaction_id'] as $field) {
            unset($document[$field]);
        }
        return $document;
    }

    private function permissions(array $permissions): array
    {
        if ($permissions === []) throw new RuntimeException('identity_permissions_required');
        foreach ($permissions as $permission) {
            if (!is_string($permission)
                || !preg_match('/^(\*|[a-z][a-z0-9_.:-]{1,127}(?:\.\*)?)$/', $permission)) {
                throw new RuntimeException('identity_permission_invalid');
            }
        }
        return array_values(array_unique($permissions));
    }

    private function roleId(string $roleId): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $roleId)) throw new RuntimeException('identity_role_invalid');
    }

    private function identifier(string $value, string $error): void
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{3,128}$/', $value)) throw new RuntimeException($error);
    }

    private function assertPassword(string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 1024) {
            throw new RuntimeException('identity_password_policy_failed');
        }
    }

    private function sessionParameters(string $deviceId, string $deviceLabel, int $ttl): void
    {
        $this->identifier($deviceId, 'identity_device_invalid');
        if (trim($deviceLabel) === '' || strlen($deviceLabel) > 128) throw new RuntimeException('identity_device_invalid');
        if ($ttl < 60 || $ttl > self::MAX_SESSION_TTL) throw new RuntimeException('identity_session_ttl_invalid');
    }

    private function passwordHash(string $value): string
    {
        return password_hash($value, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    }

    private function lookup(string $value): string { return hash_hmac('sha256', $value, $this->pepper); }
    private function secretHash(string $secret): string { return hash_hmac('sha512', $secret, $this->pepper); }
    private function sessionId(string $token): string { return 'SESSION-' . $this->lookup($token); }
    private function challengeId(string $token): string { return 'CHALLENGE-' . $this->lookup($token); }
    private function requestId(): string { return bin2hex(random_bytes(16)); }

    private function now(): int
    {
        $now = ($this->clock)();
        if (!is_int($now) && !is_float($now)) throw new RuntimeException('identity_clock_invalid');
        return (int) $now;
    }

    private function record(
        string $principal,
        string $action,
        string $requestId,
        array $context,
        bool $success = true,
        ?string $error = null,
    ): void {
        $this->audit->record(
            $principal, $action, $requestId, $success,
            hash('sha256', PhpSerializer::encode($context)), $error,
        );
    }
}
