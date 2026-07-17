<?php

declare(strict_types=1);

namespace Jah\JAS\Security;

use InvalidArgumentException;

final class RolePolicy
{
    public function __construct(private readonly array $roles)
    {
        foreach ($roles as $role => $permissions) {
            if (!is_string($role) || !preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $role) || !is_array($permissions)) throw new InvalidArgumentException('role_policy_invalid');
        }
    }

    public function allows(array $userRoles, string $permission): bool
    {
        foreach ($userRoles as $role) {
            foreach ((array) ($this->roles[(string) $role] ?? []) as $granted) {
                $granted = (string) $granted;
                if ($granted === '*' || $granted === $permission || (str_ends_with($granted, '.*') && str_starts_with($permission, substr($granted, 0, -1)))) return true;
            }
        }
        return false;
    }
}
