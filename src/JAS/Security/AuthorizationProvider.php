<?php

declare(strict_types=1);

namespace Jah\JAS\Security;

interface AuthorizationProvider extends IdentityProvider
{
    public function allows(string $token, string $permission): bool;
}
