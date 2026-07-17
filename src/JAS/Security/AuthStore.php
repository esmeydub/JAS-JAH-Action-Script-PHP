<?php

declare(strict_types=1);

namespace Jah\JAS\Security;

use Jah\DataCore\PhpSerializer;
use RuntimeException;

final class AuthStore implements IdentityProvider
{
    private string $file;
    private string $lock;
    public function __construct(string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('auth_directory_failed');
        $this->file = rtrim($directory, '/') . '/auth.jahl';
        $this->lock = rtrim($directory, '/') . '/auth.lock';
    }

    public function createUser(string $id, string $username, string $password, array $roles): void
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{3,128}$/', $id) || !preg_match('/^[a-zA-Z0-9_.-]{3,64}$/', $username)) throw new RuntimeException('auth_user_invalid');
        if (strlen($password) < 12 || strlen($password) > 1024) throw new RuntimeException('auth_password_policy_failed');
        if ($roles === []) throw new RuntimeException('auth_roles_required');
        $this->mutate(function (array &$data) use ($id, $username, $password, $roles): void {
            foreach ($data['users'] as $user) if (strcasecmp((string) $user['username'], $username) === 0) throw new RuntimeException('auth_username_exists');
            if (isset($data['users'][$id])) throw new RuntimeException('auth_user_exists');
            $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
            $data['users'][$id] = [
                'id' => $id,
                'username' => $username,
                'password_hash' => password_hash($password, $algorithm),
                'roles' => array_values(array_unique($roles)),
                'active' => true,
            ];
        });
    }

    public function login(string $username, string $password, int $ttlSeconds = 3600): string
    {
        if ($ttlSeconds < 60 || $ttlSeconds > 86_400) throw new RuntimeException('auth_session_ttl_invalid');
        $result = $this->mutate(function (array &$data) use ($username, $password, $ttlSeconds): array {
            $attemptKey = hash('sha256', strtolower($username));
            $attempt = $data['attempts'][$attemptKey] ?? ['failures' => 0, 'locked_until' => 0];
            if ((int) ($attempt['locked_until'] ?? 0) > time()) return ['error' => 'auth_login_locked'];
            $user = null;
            foreach ($data['users'] as $candidate) if (strcasecmp((string) $candidate['username'], $username) === 0) $user = $candidate;
            if (!is_array($user) || ($user['active'] ?? false) !== true || !password_verify($password, (string) $user['password_hash'])) {
                $failures = (int) ($attempt['failures'] ?? 0) + 1;
                $data['attempts'][$attemptKey] = ['failures' => $failures, 'locked_until' => $failures >= 5 ? time() + min(3600, 30 * (2 ** min(6, $failures - 5))) : 0];
                return ['error' => 'auth_credentials_invalid'];
            }
            unset($data['attempts'][$attemptKey]);
            $token = bin2hex(random_bytes(32));
            $data['sessions'][hash('sha256', $token)] = ['user_id' => $user['id'], 'expires_at' => time() + $ttlSeconds, 'created_at' => time()];
            return ['token' => $token];
        });
        if (isset($result['error'])) throw new RuntimeException((string) $result['error']);
        return (string) ($result['token'] ?? throw new RuntimeException('auth_login_failed'));
    }

    public function identity(string $token): ?array
    {
        if ($token === '') return null;
        return $this->mutate(function (array &$data) use ($token): ?array {
            $key = hash('sha256', $token);
            $session = $data['sessions'][$key] ?? null;
            if (!is_array($session) || (int) ($session['expires_at'] ?? 0) < time()) { unset($data['sessions'][$key]); return null; }
            $user = $data['users'][(string) $session['user_id']] ?? null;
            if (!is_array($user) || ($user['active'] ?? false) !== true) return null;
            return ['id' => $user['id'], 'username' => $user['username'], 'roles' => $user['roles']];
        });
    }

    public function logout(string $token): void { $this->mutate(function (array &$data) use ($token): void { unset($data['sessions'][hash('sha256', $token)]); }); }

    public function changePassword(string $token, string $currentPassword, string $newPassword): void
    {
        if (strlen($newPassword) < 12 || strlen($newPassword) > 1024 || hash_equals($currentPassword, $newPassword)) throw new RuntimeException('auth_password_policy_failed');
        $this->mutate(function (array &$data) use ($token, $currentPassword, $newPassword): void {
            $session = $data['sessions'][hash('sha256', $token)] ?? null;
            $userId = is_array($session) ? (string) ($session['user_id'] ?? '') : '';
            $user = $data['users'][$userId] ?? null;
            if (!is_array($user) || !password_verify($currentPassword, (string) $user['password_hash'])) throw new RuntimeException('auth_credentials_invalid');
            $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
            $data['users'][$userId]['password_hash'] = password_hash($newPassword, $algorithm);
            $data['users'][$userId]['password_changed_at'] = time();
            $this->removeUserSessions($data, $userId);
        });
    }

    public function revokeUserSessions(string $userId): void
    {
        $this->mutate(function (array &$data) use ($userId): void {
            if (!isset($data['users'][$userId])) throw new RuntimeException('auth_user_not_found');
            $this->removeUserSessions($data, $userId);
        });
    }

    public function setUserActive(string $userId, bool $active): void
    {
        $this->mutate(function (array &$data) use ($userId, $active): void {
            if (!isset($data['users'][$userId])) throw new RuntimeException('auth_user_not_found');
            $data['users'][$userId]['active'] = $active;
            if (!$active) $this->removeUserSessions($data, $userId);
        });
    }

    private function mutate(callable $operation): mixed
    {
        $handle = fopen($this->lock, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('auth_lock_failed');
        try {
            $data = is_file($this->file) ? PhpSerializer::decode(file_get_contents($this->file)) : null;
            if (!is_array($data)) $data = ['users' => [], 'sessions' => [], 'attempts' => []];
            $data['attempts'] ??= [];
            $result = $operation($data);
            $encoded = PhpSerializer::encode($data);
            $temporary = $this->file . '.tmp.' . bin2hex(random_bytes(4));
            if (file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded) || !rename($temporary, $this->file)) throw new RuntimeException('auth_write_failed');
            @chmod($this->file, 0600);
            return $result;
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function removeUserSessions(array &$data, string $userId): void
    {
        foreach ($data['sessions'] as $key => $session) if (($session['user_id'] ?? null) === $userId) unset($data['sessions'][$key]);
    }
}
