<?php

declare(strict_types=1);

namespace Jah\JAS\Definition;

use InvalidArgumentException;

final class EventDefinition
{
    public function __construct(
        public readonly string $domain,
        public readonly string $name,
        public readonly string $payloadType,
        public readonly int $version = 1
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.:-]{2,255}$/', $name)) throw new InvalidArgumentException('event_name_invalid');
        if (!preg_match('/^[A-Z][A-Za-z0-9_]{1,127}$/', $payloadType)) throw new InvalidArgumentException('event_type_invalid');
        if ($version < 1 || $version > 65_535) throw new InvalidArgumentException('event_version_invalid');
    }

    public function describe(): array
    {
        return ['domain' => $this->domain, 'name' => $this->name, 'payload' => $this->payloadType, 'version' => $this->version];
    }
}
