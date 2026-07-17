<?php

declare(strict_types=1);

namespace Jah\JAS\Worker;

use InvalidArgumentException;

final class WorkerDescriptor
{
    /** @param list<string> $capabilities */
    public function __construct(
        public readonly string $id,
        public readonly string $runtime,
        public readonly array $capabilities,
        public int $capacity = 1,
        public int $inFlight = 0,
        public int $lastHeartbeat = 0
    ) {
        if ($id === '' || $runtime === '' || $capacity < 1) throw new InvalidArgumentException('Descriptor de worker inválido');
        $this->lastHeartbeat = $lastHeartbeat ?: time();
    }
    public function supports(string $capability): bool
    {
        foreach ($this->capabilities as $item) {
            if ($item === '*' || $item === $capability || (str_ends_with($item, '.*') && str_starts_with($capability, substr($item, 0, -1)))) return true;
        }
        return false;
    }
    public function available(): bool { return $this->inFlight < $this->capacity; }
}
