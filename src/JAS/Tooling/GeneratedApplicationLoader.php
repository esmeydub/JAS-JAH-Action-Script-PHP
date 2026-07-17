<?php

declare(strict_types=1);

namespace Jah\JAS\Tooling;

use Jah\JAS\Definition\JasApplication;
use Jah\JAS\Jas;
use RuntimeException;

final class GeneratedApplicationLoader
{
    public function __construct(private readonly PhpDefinitionReader $reader = new PhpDefinitionReader()) {}

    public function load(string $project, string $applicationName): JasApplication
    {
        $root = realpath($project);
        if ($root === false || !is_dir($root . '/app')) throw new RuntimeException('generated_project_invalid');

        $application = Jas::application($applicationName);
        foreach ($this->definitions($root, 'Types') as $definition) {
            $this->schema($definition, ['name', 'fields', 'strict'], ['name', 'fields', 'strict'], 'generated_type_invalid');
            if (!is_string($definition['name']) || !is_array($definition['fields']) || !is_bool($definition['strict'])) {
                throw new RuntimeException('generated_type_invalid');
            }
            $application->type($definition['name'], $definition['fields'], $definition['strict']);
        }
        foreach ($this->definitions($root, 'Domains') as $definition) {
            $this->schema($definition, ['name', 'prefix', 'dependencies'], ['name', 'prefix', 'dependencies'], 'generated_domain_invalid');
            if (!is_string($definition['name']) || !is_string($definition['prefix']) || !is_array($definition['dependencies'])) {
                throw new RuntimeException('generated_domain_invalid');
            }
            $application->domain($definition['name'], $definition['prefix'], $definition['dependencies']);
        }
        foreach ($this->definitions($root, 'Events') as $definition) {
            $this->schema($definition, ['domain', 'name', 'payload', 'version'], ['domain', 'name', 'payload', 'version'], 'generated_event_invalid');
            if (!is_string($definition['domain']) || !is_string($definition['name']) || !is_string($definition['payload']) || !is_int($definition['version'])) {
                throw new RuntimeException('generated_event_invalid');
            }
            $application->event($definition['domain'], $definition['name'], $definition['payload'], $definition['version']);
        }
        foreach ($this->definitions($root, 'Actions') as $definition) {
            $allowed = ['domain', 'name', 'input', 'output', 'capability', 'audit', 'idempotent', 'transactional', 'emits', 'queue', 'partition_by', 'max_attempts'];
            $required = ['domain', 'name', 'input', 'output', 'capability'];
            $this->schema($definition, $allowed, $required, 'generated_action_invalid');
            foreach ($required as $field) {
                if (!is_string($definition[$field])) throw new RuntimeException('generated_action_invalid');
            }
            foreach (['audit', 'idempotent', 'transactional'] as $field) {
                if (array_key_exists($field, $definition) && !is_bool($definition[$field])) throw new RuntimeException('generated_action_invalid');
            }
            if (array_key_exists('emits', $definition) && !is_string($definition['emits'])) throw new RuntimeException('generated_action_invalid');
            if (array_key_exists('queue', $definition)) {
                if (!is_string($definition['queue']) || !isset($definition['partition_by']) || !is_string($definition['partition_by'])) {
                    throw new RuntimeException('generated_action_invalid');
                }
                if (array_key_exists('max_attempts', $definition) && !is_int($definition['max_attempts'])) throw new RuntimeException('generated_action_invalid');
            } elseif (array_key_exists('partition_by', $definition) || array_key_exists('max_attempts', $definition)) {
                throw new RuntimeException('generated_action_invalid');
            }
            $action = $application->action($definition['domain'], $definition['name'])
                ->input($definition['input'])
                ->output($definition['output'])
                ->requires($definition['capability'])
                ->audit($definition['audit'] ?? true)
                ->idempotent($definition['idempotent'] ?? false)
                ->transactional($definition['transactional'] ?? false);
            if (isset($definition['emits'])) $action->emits((string) $definition['emits']);
            if (isset($definition['queue'])) {
                $action->queued(
                    (string) $definition['queue'],
                    (string) ($definition['partition_by'] ?? ''),
                    (int) ($definition['max_attempts'] ?? 3),
                );
            }
        }
        return $application;
    }

    /** @return list<array<string,mixed>> */
    private function definitions(string $root, string $kind): array
    {
        $directory = $root . '/app/' . $kind;
        if (!is_dir($directory)) throw new RuntimeException('generated_definition_directory_missing');
        $files = glob($directory . '/*.php') ?: [];
        sort($files, SORT_STRING);
        $definitions = [];
        foreach ($files as $file) {
            $resolved = realpath($file);
            if ($resolved === false || dirname($resolved) !== $directory || !is_file($resolved)) {
                throw new RuntimeException('generated_definition_path_invalid');
            }
            $definitions[] = $this->reader->read($resolved);
        }
        return $definitions;
    }

    /** @param list<string> $allowed @param list<string> $required */
    private function schema(array $definition, array $allowed, array $required, string $error): void
    {
        if (array_diff(array_keys($definition), $allowed) !== [] || array_diff($required, array_keys($definition)) !== []) {
            throw new RuntimeException($error);
        }
    }
}
