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

        foreach (($previous['domains'] ?? []) as $name => $old) {
            $new = $next['domains'][$name] ?? null;
            if (!is_array($old) || !is_array($new)) continue;
            if (($old['prefix'] ?? null) !== ($new['prefix'] ?? null)) $breaking[] = "domain_prefix_changed:{$name}";
            $oldDependencies = is_array($old['dependencies'] ?? null) ? $old['dependencies'] : [];
            $newDependencies = is_array($new['dependencies'] ?? null) ? $new['dependencies'] : [];
            foreach (array_diff($oldDependencies, $newDependencies) as $dependency) $breaking[] = "domain_dependency_removed:{$name}:{$dependency}";
            foreach (array_diff($newDependencies, $oldDependencies) as $dependency) $warnings[] = "domain_dependency_added:{$name}:{$dependency}";
        }

        foreach (($previous['contracts'] ?? []) as $name => $old) {
            $new = $next['contracts'][$name] ?? null;
            if (!is_array($old) || !is_array($new)) continue;
            foreach (['input', 'output', 'capability', 'audit', 'idempotent', 'emits'] as $field) {
                if (($old[$field] ?? null) !== ($new[$field] ?? null)) $breaking[] = "action_changed:{$name}:{$field}";
            }
        }
        foreach (($previous['types'] ?? []) as $name => $old) {
            $new = $next['types'][$name] ?? null;
            if (!is_array($old) || !is_array($new)) continue;
            if (($old['strict'] ?? null) !== ($new['strict'] ?? null)) $breaking[] = "type_strictness_changed:{$name}";
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
        $this->addedKeys('domain', $previous['domains'] ?? [], $next['domains'] ?? [], $warnings);
        $this->addedKeys('action', $previous['contracts'] ?? [], $next['contracts'] ?? [], $warnings);
        $this->addedKeys('type', $previous['types'] ?? [], $next['types'] ?? [], $warnings);
        $this->addedKeys('event', $previous['events'] ?? [], $next['events'] ?? [], $warnings);
        return new CompatibilityReport(array_values(array_unique($breaking)), array_values(array_unique($warnings)));
    }

    private function removedKeys(string $kind, array $previous, array $next, array &$breaking): void
    {
        foreach (array_diff(array_keys($previous), array_keys($next)) as $name) $breaking[] = "{$kind}_removed:{$name}";
    }

    private function addedKeys(string $kind, array $previous, array $next, array &$warnings): void
    {
        foreach (array_diff(array_keys($next), array_keys($previous)) as $name) $warnings[] = "{$kind}_added:{$name}";
    }
}
