<?php

declare(strict_types=1);

namespace Jah\DataCore;

interface CompatibleDataCoreMigration extends DataCoreMigration
{
    public function backwardCompatible(): bool;
}
