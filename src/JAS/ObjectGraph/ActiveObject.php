<?php

declare(strict_types=1);

namespace Jah\JAS\ObjectGraph;

use InvalidArgumentException;

final class ActiveObject
{
    /** @var array<string,list<string>> */
    private array $bindings = [];
    private int $version = 0;

    public function __construct(public readonly string $id, public readonly string $type, private array $state = [])
    {
        if (strlen($id) > 128 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $id)) throw new InvalidArgumentException('ID de objeto JAS inválido');
        if (strlen($type) > 128 || !preg_match('/^[A-Za-z][A-Za-z0-9_.:-]*$/', $type)) throw new InvalidArgumentException('Tipo de objeto JAS inválido');
    }

    public static function restore(string $id, string $type, array $state, array $bindings, int $version): self
    {
        $object = new self($id, $type, $state);
        foreach ($bindings as $event => $actions) {
            foreach ((array)$actions as $action) $object->on((string)$event, (string)$action);
        }
        $object->version = max(0, $version);
        return $object;
    }

    public function on(string $event, string $action): self
    {
        if (strlen($event) > 128 || strlen($action) > 255 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $event) || !preg_match('/^[a-z][a-z0-9_.:-]*$/', $action)) {
            throw new InvalidArgumentException('Binding JAS inválido');
        }
        $this->bindings[$event] ??= [];
        if (!in_array($action, $this->bindings[$event], true)) $this->bindings[$event][] = $action;
        return $this;
    }

    public function actionsFor(string $event): array { return $this->bindings[$event] ?? []; }
    public function bindings(): array { return $this->bindings; }
    public function state(): array { return $this->state; }
    public function version(): int { return $this->version; }
    public function patch(array $changes, ?int $expectedVersion = null): void
    {
        if ($expectedVersion !== null && $expectedVersion !== $this->version) {
            throw new InvalidArgumentException("Conflicto de versión en {$this->id}");
        }
        $this->state = array_replace($this->state, $changes);
        $this->version++;
    }
}
