<?php

declare(strict_types=1);

namespace Jah\DataCore;

use Jah\JAS\Persistence\AuditJournal;
use Jah\JAS\Security\DataGovernancePolicy;
use RuntimeException;

final class DataRetentionService
{
    public function __construct(
        private readonly DataCoreDatabase $database,
        private readonly DataGovernancePolicy $policy,
        private readonly AuditJournal $audit
    ) {}

    /** @return array{collection:string,expired:int,held:int,deleted:int,dry_run:bool} */
    public function enforce(string $collection, string $principal, bool $dryRun = true, ?int $now = null): array
    {
        if ($principal === '') throw new RuntimeException('retention_principal_required');
        $rule = $this->policy->rule($collection); $now ??= time();
        $cutoff = $now - ((int) $rule['retention_days'] * 86_400);
        $documents = $this->database->scan($collection, static fn(array $document): bool => true, 10_000);
        $expired = 0; $held = 0; $deleted = 0;
        foreach ($documents as $document) {
            $timestamp = (float) ($document[$rule['timestamp_field']] ?? 0);
            if ($timestamp <= 0 || $timestamp >= $cutoff) continue;
            $expired++;
            if (($document[$rule['legal_hold_field']] ?? false) === true) { $held++; continue; }
            if (!$dryRun) {
                $this->database->delete($collection, (string) $document['id'], (int) $document['_version']);
                $deleted++;
            }
        }
        $requestId = 'retention-' . $collection . '-' . $now;
        $fingerprint = hash('sha256', serialize([$collection, $cutoff, $expired, $held, $deleted, $dryRun]));
        $this->audit->record($principal, 'datacore.retention.enforce', $requestId, true, $fingerprint);
        return ['collection' => $collection, 'expired' => $expired, 'held' => $held, 'deleted' => $deleted, 'dry_run' => $dryRun];
    }
}
