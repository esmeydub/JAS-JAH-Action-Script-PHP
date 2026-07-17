<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

final class SafeHtml
{
    public function __construct(private readonly string $value) {}
    public function value(): string { return $this->value; }
    public function __toString(): string { return $this->value; }
}
