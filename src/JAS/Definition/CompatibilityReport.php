<?php

declare(strict_types=1);

namespace Jah\JAS\Definition;

final class CompatibilityReport
{
    /** @param list<string> $breaking @param list<string> $warnings */
    public function __construct(public readonly array $breaking, public readonly array $warnings) {}
    public function compatible(): bool { return $this->breaking === []; }
    public function toArray(): array { return ['compatible' => $this->compatible(), 'breaking' => $this->breaking, 'warnings' => $this->warnings]; }
}
