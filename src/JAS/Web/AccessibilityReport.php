<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use RuntimeException;

final class AccessibilityReport
{
    /**
     * @param list<array{code:string,criterion:string,severity:string,message:string,element:string}> $findings
     * @param array<string,string> $manualChecks
     */
    public function __construct(
        public readonly array $findings,
        public readonly array $manualChecks,
    ) {}

    public function passesAutomatedChecks(): bool
    {
        foreach ($this->findings as $finding) if ($finding['severity'] === 'error') return false;
        return true;
    }

    /** @param array<string,string> $evidence criterion => evidence reference */
    public function isComplete(array $evidence): bool
    {
        if (!$this->passesAutomatedChecks()) return false;
        foreach ($this->manualChecks as $criterion => $_description) {
            if (!isset($evidence[$criterion]) || !is_string($evidence[$criterion]) || trim($evidence[$criterion]) === '') return false;
        }
        return true;
    }

    public function assertAutomatedPass(): void
    {
        if ($this->passesAutomatedChecks()) return;
        $codes = [];
        foreach ($this->findings as $finding) if ($finding['severity'] === 'error') $codes[] = $finding['code'];
        throw new RuntimeException('accessibility_audit_failed:' . implode(',', array_values(array_unique($codes))));
    }

    public function summary(): array
    {
        $errors = 0; $warnings = 0;
        foreach ($this->findings as $finding) {
            if ($finding['severity'] === 'error') $errors++;
            if ($finding['severity'] === 'warning') $warnings++;
        }
        return [
            'standard' => 'WCAG 2.2',
            'target' => 'AA',
            'automated_pass' => $errors === 0,
            'errors' => $errors,
            'warnings' => $warnings,
            'manual_checks_required' => count($this->manualChecks),
        ];
    }
}
