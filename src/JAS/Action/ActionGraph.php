<?php

declare(strict_types=1);

namespace Jah\JAS\Action;

use InvalidArgumentException;

final class ActionGraph
{
    /** @var array<string,ActionNode> */
    private array $nodes = [];

    public function add(ActionNode $node): self
    {
        if (isset($this->nodes[$node->id])) {
            throw new InvalidArgumentException("Nodo duplicado: {$node->id}");
        }
        $this->nodes[$node->id] = $node;
        return $this;
    }

    /** @return array<string,ActionNode> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    public function validate(): void
    {
        foreach ($this->nodes as $node) {
            foreach ($node->dependencies as $dependency) {
                if (!isset($this->nodes[$dependency])) {
                    throw new InvalidArgumentException("Dependencia inexistente {$dependency} en {$node->id}");
                }
            }
        }
        $visiting = [];
        $visited = [];
        $visit = function (string $id) use (&$visit, &$visiting, &$visited): void {
            if (isset($visited[$id])) return;
            if (isset($visiting[$id])) {
                throw new InvalidArgumentException("Ciclo detectado en el grafo JAS: {$id}");
            }
            $visiting[$id] = true;
            foreach ($this->nodes[$id]->dependencies as $dependency) $visit($dependency);
            unset($visiting[$id]);
            $visited[$id] = true;
        };
        foreach (array_keys($this->nodes) as $id) $visit($id);
    }
}
