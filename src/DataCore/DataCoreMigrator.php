<?php

declare(strict_types=1);

namespace Jah\DataCore;

use RuntimeException;
use Throwable;

final class DataCoreMigrator
{
    public function __construct(private readonly DataCoreTurbo $storage)
    {
    }

    /** @param list<DataCoreMigration> $migrations */
    public function migrate(array $migrations, bool $allowBreaking = false): int
    {
        $migrations = $this->ordered($migrations);
        $applied = 0;
        foreach ($migrations as $migration) {
            if ($migration instanceof CompatibleDataCoreMigration
                && !$migration->backwardCompatible()
                && !$allowBreaking) {
                throw new RuntimeException('datacore_breaking_migration_requires_approval');
            }

            $existing = $this->record($migration->version());
            if ($existing !== null && ($existing['state'] ?? 'APPLIED') === 'APPLIED') {
                if (($existing['checksum'] ?? null) !== $migration->checksum()) {
                    throw new RuntimeException('datacore_migration_checksum_changed');
                }
                continue;
            }
            if ($existing !== null && ($existing['state'] ?? null) === 'PREPARED') {
                $this->rollbackPartial($migration);
            }

            $this->writeRecord($migration, 'PREPARED');
            try {
                $migration->up($this->storage);
                $this->storage->flush();
                $this->writeRecord($migration, 'APPLIED');
                $applied++;
            } catch (Throwable $error) {
                $this->rollbackPartial($migration);
                $this->writeRecord($migration, 'ROLLED_BACK', $error->getMessage());
                throw $error;
            }
        }
        return $applied;
    }

    /** @param list<DataCoreMigration> $migrations */
    public function rollback(array $migrations, int $targetVersion): int
    {
        if ($targetVersion < 0) throw new RuntimeException('datacore_migration_target_invalid');
        $ordered = array_reverse($this->ordered($migrations));
        $rolledBack = 0;
        foreach ($ordered as $migration) {
            if ($migration->version() <= $targetVersion) continue;
            $record = $this->record($migration->version());
            if ($record === null || ($record['state'] ?? 'APPLIED') !== 'APPLIED') continue;
            if (!$migration instanceof ReversibleDataCoreMigration) {
                throw new RuntimeException('datacore_migration_not_reversible:' . $migration->version());
            }
            $migration->down($this->storage);
            $this->storage->flush();
            $this->writeRecord($migration, 'ROLLED_BACK');
            $rolledBack++;
        }
        return $rolledBack;
    }

    private function rollbackPartial(DataCoreMigration $migration): void
    {
        if (!$migration instanceof ReversibleDataCoreMigration) {
            throw new RuntimeException('datacore_partial_migration_not_reversible:' . $migration->version());
        }
        $migration->down($this->storage);
        $this->storage->flush();
    }

    private function record(int $version): ?array
    {
        return $this->storage->find('_jas_migrations', 'migration-' . $version);
    }

    private function writeRecord(
        DataCoreMigration $migration,
        string $state,
        ?string $error = null,
    ): void {
        $this->storage->insert('_jas_migrations', [
            'id' => 'migration-' . $migration->version(),
            'version' => $migration->version(),
            'name' => $migration->name(),
            'checksum' => $migration->checksum(),
            'state' => $state,
            'error' => $error,
            'recorded_at' => microtime(true),
        ]);
        $this->storage->flush();
    }

    /** @param list<DataCoreMigration> $migrations @return list<DataCoreMigration> */
    private function ordered(array $migrations): array
    {
        usort($migrations, static fn(DataCoreMigration $a, DataCoreMigration $b): int => $a->version() <=> $b->version());
        $seen = [];
        foreach ($migrations as $migration) {
            if ($migration->version() < 1 || isset($seen[$migration->version()])) {
                throw new RuntimeException('datacore_migration_version_invalid');
            }
            $seen[$migration->version()] = true;
        }
        return $migrations;
    }
}
