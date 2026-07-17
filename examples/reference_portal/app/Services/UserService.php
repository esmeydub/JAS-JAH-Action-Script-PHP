<?php

declare(strict_types=1);

namespace JAS\ReferencePortal;

use Jah\JAS\Security\InstitutionalIdentityService;
use RuntimeException;

final class UserService
{
    private const ROLES = [
        'citizen' => 'citizen',
        'moderator' => 'moderator',
        'auditor' => 'auditor',
    ];

    public function __construct(private readonly InstitutionalIdentityService $identity) {}

    public function register(string $actor, array $command): array
    {
        $role = strtolower((string) $command['role']);
        $roleId = self::ROLES[$role] ?? throw new RuntimeException('portal_role_not_allowed');
        $this->identity->createUser(
            $actor,
            (string) $command['id'],
            (string) $command['username'],
            (string) $command['display_name'],
            (string) $command['password'],
        );
        $this->identity->assignRole($actor, (string) $command['id'], $roleId);
        return ['id' => $command['id'], 'username' => strtolower((string) $command['username']), 'role' => $role];
    }
}
