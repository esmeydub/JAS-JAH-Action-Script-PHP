<?php

declare(strict_types=1);

namespace Jah\JAS\Definition;

use InvalidArgumentException;

final class DomainDefinition
{
    /** @param list<string> $dependencies */
    public function __construct(
        public readonly string $name,
        public readonly string $actionPrefix,
        public readonly array $dependencies = []
    ) {
        if (!preg_match('/^[A-Z][A-Za-z0-9]{1,63}$/', $name)) {
            throw new InvalidArgumentException('domain_name_invalid');
        }
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $actionPrefix)) {
            throw new InvalidArgumentException('domain_action_prefix_invalid');
        }
        if (count($dependencies) !== count(array_unique($dependencies))) {
            throw new InvalidArgumentException('domain_dependencies_duplicated');
        }
        foreach ($dependencies as $dependency) {
            if (!is_string($dependency) || !preg_match('/^[A-Z][A-Za-z0-9]{1,63}$/', $dependency) || $dependency === $name) {
                throw new InvalidArgumentException('domain_dependency_invalid');
            }
        }
    }

    public function ownsAction(string $action): bool
    {
        return str_starts_with($action, $this->actionPrefix . '.');
    }
}
