<?php

declare(strict_types=1);

namespace Jah\JAS\Security;

use RuntimeException;

final class CapabilityPolicy
{
    /** @param array<string,list<string>> $grants */
    public function __construct(private array $grants = []) {}

    public function grant(string $principal, string ...$capabilities): void
    {
        $this->grants[$principal] ??= [];
        $this->grants[$principal] = array_values(array_unique(array_merge($this->grants[$principal], $capabilities)));
    }

    public function allows(string $principal, string $capability): bool
    {
        foreach ($this->grants[$principal] ?? [] as $grant) {
            if ($grant === '*' || $grant === $capability) return true;
            if (str_ends_with($grant, '.*') && str_starts_with($capability, substr($grant, 0, -1))) return true;
        }
        return false;
    }

    public function assertAllowed(string $principal, string $capability): void
    {
        if (!$this->allows($principal, $capability)) {
            throw new RuntimeException("SALK denegó {$capability} para {$principal}");
        }
    }

    /** @return array<string,list<string>> */
    public function export(): array { return $this->grants; }
}
