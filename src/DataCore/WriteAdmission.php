<?php

declare(strict_types=1);

namespace Jah\DataCore;

interface WriteAdmission
{
    public function assertWritable(string $operation, int $estimatedBytes, bool $essential = false): void;
}
