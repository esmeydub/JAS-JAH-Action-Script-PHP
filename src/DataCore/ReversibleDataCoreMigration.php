<?php

declare(strict_types=1);

namespace Jah\DataCore;

interface ReversibleDataCoreMigration extends DataCoreMigration
{
    public function down(DataCoreTurbo $storage): void;
}
