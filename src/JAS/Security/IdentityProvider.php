<?php

declare(strict_types=1);

namespace Jah\JAS\Security;

interface IdentityProvider
{
    public function identity(string $token): ?array;
}
