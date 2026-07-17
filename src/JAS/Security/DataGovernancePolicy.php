<?php

declare(strict_types=1);

namespace Jah\JAS\Security;

use RuntimeException;

final class DataGovernancePolicy
{
    /** @var array<string,array{purpose:string,retention_days:int,timestamp_field:string,legal_hold_field:string}> */
    private array $collections = [];

    public function collection(string $name, string $purpose, int $retentionDays, string $timestampField = '_created_at', string $legalHoldField = '_legal_hold'): self
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $name)) throw new RuntimeException('governance_collection_invalid');
        if (trim($purpose) === '' || strlen($purpose) > 500) throw new RuntimeException('governance_purpose_invalid');
        if ($retentionDays < 1 || $retentionDays > 36_500) throw new RuntimeException('governance_retention_invalid');
        foreach ([$timestampField, $legalHoldField] as $field) if (!preg_match('/^_?[a-z][a-z0-9_]{0,127}$/i', $field)) throw new RuntimeException('governance_field_invalid');
        $this->collections[$name] = ['purpose' => $purpose, 'retention_days' => $retentionDays, 'timestamp_field' => $timestampField, 'legal_hold_field' => $legalHoldField];
        return $this;
    }

    public function rule(string $collection): array
    {
        return $this->collections[$collection] ?? throw new RuntimeException('governance_rule_required');
    }

    public function describe(): array { $rules = $this->collections; ksort($rules); return $rules; }
}
