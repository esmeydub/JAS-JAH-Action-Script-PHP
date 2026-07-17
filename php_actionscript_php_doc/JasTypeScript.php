<?php

declare(strict_types=1);

namespace Jah;

use Jah\JAS\Type\TypeRegistry;

/**
 * Runtime type contracts for JAS. It validates PHP values without TypeScript.
 */
final class JasTypeScript
{
    private TypeRegistry $registry;

    public function __construct() { $this->registry = new TypeRegistry(); }

    public function declare(string $type, string $alias): void
    {
        $this->registry->alias($alias, $type);
    }

    public function getAlias(string $alias): ?string
    {
        return $this->registry->aliasExpression($alias);
    }

    public function define(string $name, array $shape, bool $strict = false): self
    {
        $this->registry->define($name, $shape, $strict);
        return $this;
    }

    public function defineStrict(string $name, array $shape): self
    {
        return $this->define($name, $shape, true);
    }

    public function validate(string $type, mixed $value): bool
    {
        return $this->registry->validate($type, $value);
    }

    public function assert(string $type, mixed $value, string $label = 'value'): mixed
    {
        return $this->registry->assert($type, $value, $label);
    }

    public function compile(string $type, mixed $value): mixed
    {
        return $this->assert($type, $value);
    }

}
