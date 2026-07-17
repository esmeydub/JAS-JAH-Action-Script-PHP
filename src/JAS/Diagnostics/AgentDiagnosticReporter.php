<?php

declare(strict_types=1);

namespace Jah\JAS\Diagnostics;

final class AgentDiagnosticReporter
{
    public function render(Diagnostic $diagnostic): string
    {
        $lines = [
            'CODE=' . $diagnostic->code,
            'INCIDENT=' . $diagnostic->id,
            'SEVERITY=' . strtoupper($diagnostic->severity),
            'COMPONENT=' . $this->value($diagnostic->component),
        ];
        if ($diagnostic->file !== null) $lines[] = 'FILE=' . $this->value($diagnostic->file);
        if ($diagnostic->line !== null) $lines[] = 'LINE=' . $diagnostic->line;
        foreach ($diagnostic->context as $key => $value) {
            if (is_array($value)) continue;
            $lines[] = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $key) ?: 'CONTEXT') . '=' . $this->value($value);
        }
        if ($diagnostic->suggestion !== null) $lines[] = 'SUGGESTION=' . $this->value($diagnostic->suggestion);
        $lines[] = 'STATUS=' . strtoupper($diagnostic->status);
        return implode("\n", $lines) . "\n";
    }

    private function value(mixed $value): string
    {
        if ($value === null) return 'NULL';
        if (is_bool($value)) return $value ? 'TRUE' : 'FALSE';
        return str_replace(["\r", "\n", "="], [' ', ' ', ':'], (string) $value);
    }
}
