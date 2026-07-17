<?php

declare(strict_types=1);

namespace Jah\DataCore;

interface DataCoreMigration
{
    public function version(): int;
    public function name(): string;
    public function checksum(): string;
    public function up(DataCoreTurbo $storage): void;
}
