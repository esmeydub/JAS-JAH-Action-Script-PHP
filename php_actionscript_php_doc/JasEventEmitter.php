<?php

declare(strict_types=1);

namespace Jah;

final class JasEventEmitter
{
    private array $listeners = [];

    public function on(string $event, callable $listener): self
    {
        $this->listeners[$event][] = ['listener' => $listener, 'once' => false];
        return $this;
    }

    public function once(string $event, callable $listener): self
    {
        $this->listeners[$event][] = ['listener' => $listener, 'once' => true];
        return $this;
    }

    public function emit(string $event, mixed ...$arguments): bool
    {
        if (empty($this->listeners[$event])) {
            return false;
        }
        $remaining = [];
        foreach ($this->listeners[$event] as $entry) {
            ($entry['listener'])(...$arguments);
            if (!$entry['once']) {
                $remaining[] = $entry;
            }
        }
        $this->listeners[$event] = $remaining;
        return true;
    }

    public function off(string $event): void
    {
        unset($this->listeners[$event]);
    }
}
