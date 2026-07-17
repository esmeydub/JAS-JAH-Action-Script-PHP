<?php

declare(strict_types=1);

namespace Jah\JAS\Tooling;

use Jah\JAS\Type\TypeRegistry;
use RuntimeException;

final class DefinitionEditor
{
    public function __construct(private readonly PhpDefinitionStore $store = new PhpDefinitionStore()) {}

    public function addTypeField(string $project, string $type, string $field, string $descriptor): array
    {
        if (!preg_match('/^[A-Z][A-Za-z0-9_]{1,127}$/', $type)
            || !preg_match('/^[a-z_][a-z0-9_]{0,127}\??$/', $field)
            || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,127}(?:\[\])?$/', $descriptor)) {
            throw new RuntimeException('definition_type_field_invalid');
        }
        $file = $this->definitionFile($project, 'Types', $type . '.php');
        return $this->store->update($file, static function (array $definition) use ($type, $field, $descriptor): array {
            if (($definition['name'] ?? null) !== $type || !isset($definition['fields']) || !is_array($definition['fields'])) {
                throw new RuntimeException('generated_type_invalid');
            }
            if (array_key_exists($field, $definition['fields'])) throw new RuntimeException('definition_type_field_exists');
            $fields = $definition['fields'];
            $fields[$field] = $descriptor;
            (new TypeRegistry())->define($type, $fields, (bool) ($definition['strict'] ?? true));
            $definition['fields'] = $fields;
            return $definition;
        });
    }

    public function addDomainDependency(string $project, string $domain, string $dependency): array
    {
        foreach ([$domain, $dependency] as $name) {
            if (!preg_match('/^[A-Z][A-Za-z0-9]{1,63}$/', $name)) throw new RuntimeException('definition_domain_invalid');
        }
        if ($domain === $dependency) throw new RuntimeException('definition_domain_self_dependency');
        $directory = $this->directory($project, 'Domains');
        $graph = [];
        foreach (glob($directory . '/*.php') ?: [] as $domainFile) {
            $candidate = $this->store->read($domainFile);
            if (!isset($candidate['name'], $candidate['dependencies']) || !is_string($candidate['name']) || !is_array($candidate['dependencies'])) {
                throw new RuntimeException('generated_domain_invalid');
            }
            foreach ($candidate['dependencies'] as $declaredDependency) {
                if (!is_string($declaredDependency)) throw new RuntimeException('generated_domain_invalid');
            }
            $graph[$candidate['name']] = $candidate['dependencies'];
        }
        if (!array_key_exists($domain, $graph) || !array_key_exists($dependency, $graph)) throw new RuntimeException('definition_not_found');
        $graph[$domain][] = $dependency;
        $this->assertAcyclic($graph);
        $file = $this->definitionFile($project, 'Domains', $domain . '.php');
        return $this->store->update($file, static function (array $definition) use ($domain, $dependency): array {
            if (($definition['name'] ?? null) !== $domain || !isset($definition['dependencies']) || !is_array($definition['dependencies'])) {
                throw new RuntimeException('generated_domain_invalid');
            }
            if (in_array($dependency, $definition['dependencies'], true)) throw new RuntimeException('definition_domain_dependency_exists');
            $definition['dependencies'][] = $dependency;
            sort($definition['dependencies'], SORT_STRING);
            return $definition;
        });
    }

    public function configureAction(string $project, string $action, string $input, string $output, string $capability): array
    {
        if (!preg_match('/^[a-z][a-z0-9_.:-]{2,255}$/', $action)
            || !preg_match('/^[A-Z][A-Za-z0-9_]{1,127}$/', $input)
            || !preg_match('/^[A-Z][A-Za-z0-9_]{1,127}$/', $output)
            || !preg_match('/^[a-z][a-z0-9_.:*\-]{2,255}$/', $capability)) {
            throw new RuntimeException('definition_action_contract_invalid');
        }
        foreach ([$input, $output] as $type) {
            $typeDefinition = $this->store->read($this->definitionFile($project, 'Types', $type . '.php'));
            if (($typeDefinition['name'] ?? null) !== $type) throw new RuntimeException('generated_type_invalid');
        }
        $file = $this->findAction($project, $action);
        return $this->store->update($file, static function (array $definition) use ($action, $input, $output, $capability): array {
            if (($definition['name'] ?? null) !== $action) throw new RuntimeException('generated_action_invalid');
            $definition['input'] = $input;
            $definition['output'] = $output;
            $definition['capability'] = $capability;
            return $definition;
        });
    }

    private function findAction(string $project, string $action): string
    {
        $directory = $this->directory($project, 'Actions');
        $match = null;
        foreach (glob($directory . '/*.php') ?: [] as $file) {
            if (($this->store->read($file)['name'] ?? null) !== $action) continue;
            if ($match !== null) throw new RuntimeException('definition_action_duplicated');
            $match = $file;
        }
        return $match ?? throw new RuntimeException('definition_action_not_found');
    }

    private function definitionFile(string $project, string $kind, string $name): string
    {
        $file = $this->directory($project, $kind) . '/' . $name;
        if (!is_file($file) || is_link($file)) throw new RuntimeException('definition_not_found');
        return $file;
    }

    /** @param array<string,list<string>> $graph */
    private function assertAcyclic(array $graph): void
    {
        $visiting = [];
        $visited = [];
        $visit = function (string $domain) use (&$visit, &$visiting, &$visited, $graph): void {
            if (isset($visited[$domain])) return;
            if (isset($visiting[$domain])) throw new RuntimeException('definition_domain_dependency_cycle');
            if (!array_key_exists($domain, $graph)) throw new RuntimeException('definition_domain_dependency_not_found');
            $visiting[$domain] = true;
            foreach ($graph[$domain] as $dependency) {
                $visit($dependency);
            }
            unset($visiting[$domain]);
            $visited[$domain] = true;
        };
        foreach (array_keys($graph) as $domain) $visit($domain);
    }

    private function directory(string $project, string $kind): string
    {
        $root = realpath($project);
        $directory = $root === false ? false : realpath($root . '/app/' . $kind);
        if ($directory === false || !is_dir($directory)) throw new RuntimeException('definition_project_invalid');
        return $directory;
    }
}
