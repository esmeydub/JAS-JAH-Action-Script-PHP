<?php

declare(strict_types=1);

namespace Jah\JAS\Definition;

final class CompatibilityChecker
{
    public function compare(array $previous, array $next): CompatibilityReport
    {
        $breaking = [];
        $warnings = [];
        $this->removedKeys('domain', $previous['domains'] ?? [], $next['domains'] ?? [], $breaking);
        $this->removedKeys('action', $previous['contracts'] ?? [], $next['contracts'] ?? [], $breaking);
        $this->removedKeys('type', $previous['types'] ?? [], $next['types'] ?? [], $breaking);
        $this->removedKeys('event', $previous['events'] ?? [], $next['events'] ?? [], $breaking);

        foreach (($previous['contracts'] ?? []) as $name => $old) {
            $new = $next['contracts'][$name] ?? null;
            if (!is_array($new)) continue;
            foreach (['input', 'output', 'capability'] as $field) {
                if (($old[$field] ?? null) !== ($new[$field] ?? null)) $breaking[] = "action_changed:{$name}:{$field}";
            }
        }
        foreach (($previous['types'] ?? []) as $name => $old) {
            $new = $next['types'][$name] ?? null;
            if (!is_array($new)) continue;
            $oldFields = $old['fields'] ?? [];
            $newFields = $new['fields'] ?? [];
            foreach ($oldFields as $field => $type) {
                if (!array_key_exists($field, $newFields)) $breaking[] = "type_field_removed:{$name}:{$field}";
                elseif ($newFields[$field] !== $type) $breaking[] = "type_field_changed:{$name}:{$field}";
            }
            foreach (array_diff_key($newFields, $oldFields) as $field => $type) {
                if (!str_ends_with((string) $field, '?')) $breaking[] = "required_type_field_added:{$name}:{$field}";
                else $warnings[] = "optional_type_field_added:{$name}:{$field}";
            }
        }
        foreach (($next['events'] ?? []) as $key => $event) {
            $name = (string) ($event['name'] ?? '');
            $version = (int) ($event['version'] ?? 0);
            foreach (($previous['events'] ?? []) as $old) {
                if (($old['name'] ?? null) === $name && (int) ($old['version'] ?? 0) === $version && ($old['payload'] ?? null) !== ($event['payload'] ?? null)) {
                    $breaking[] = "event_payload_changed_without_version:{$key}";
                }
            }
        }
        return new CompatibilityReport(array_values(array_unique($breaking)), array_values(array_unique($warnings)));
    }

    private function removedKeys(string $kind, array $previous, array $next, array &$breaking): void
    {
        foreach (array_diff(array_keys($previous), array_keys($next)) as $name) $breaking[] = "{$kind}_removed:{$name}";
    }
}
