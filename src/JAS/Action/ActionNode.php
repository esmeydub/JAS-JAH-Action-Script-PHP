<?php

declare(strict_types=1);

namespace Jah\JAS\Action;

use InvalidArgumentException;

final class ActionNode
{
    /** @param list<string> $dependencies */
    public function __construct(
        public readonly string $id,
        public readonly string $action,
        public readonly array $payload = [],
        public readonly array $dependencies = [],
        public readonly int $priority = 0
    ) {
        if (strlen($id) > 255 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $id)) {
            throw new InvalidArgumentException('ID de nodo JAS inválido');
        }
        if (strlen($action) > 255 || !preg_match('/^[a-z][a-z0-9_.:-]*$/', $action)) {
            throw new InvalidArgumentException('Nombre de acción JAS inválido');
        }
        if (count($dependencies) !== count(array_unique($dependencies))) {
            throw new InvalidArgumentException('Dependencias JAS duplicadas');
        }
        foreach ($dependencies as $dependency) {
            if (!is_string($dependency) || strlen($dependency) > 255 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $dependency)) {
                throw new InvalidArgumentException('Dependencia JAS inválida');
            }
        }
    }
}
