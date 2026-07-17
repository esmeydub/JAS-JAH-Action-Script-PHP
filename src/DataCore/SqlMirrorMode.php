<?php

declare(strict_types=1);

namespace Jah\DataCore;

enum SqlMirrorMode: string
{
    case DataCorePrimary = 'datacore-primary';
    case ReadOnlyMirror = 'read-only-mirror';
    case GovernedSqlMigration = 'governed-sql-migration';
}
