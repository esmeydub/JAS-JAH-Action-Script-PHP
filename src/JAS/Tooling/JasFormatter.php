<?php

declare(strict_types=1);

namespace Jah\JAS\Tooling;

use RuntimeException;

final class JasFormatter
{
    public function __construct(private readonly PhpDefinitionStore $store = new PhpDefinitionStore()) {}

    /** @return array{ok:bool,checked:int,changed:list<string>} */
    public function format(string $project, bool $apply = true): array
    {
        $root = realpath($project);
        if ($root === false || !is_dir($root . '/app')) throw new RuntimeException('formatter_project_invalid');
        $files = [];
        foreach (['Types', 'Domains', 'Events', 'Actions'] as $kind) {
            $directory = realpath($root . '/app/' . $kind);
            if ($directory === false) throw new RuntimeException('formatter_directory_missing');
            foreach (glob($directory . '/*.php') ?: [] as $file) $files[] = $file;
        }
        sort($files, SORT_STRING);
        $preflight = [];
        foreach ($files as $file) {
            $definition = $this->store->read($file);
            $current = file_get_contents($file);
            if (!is_string($current)) throw new RuntimeException('formatter_read_failed');
            $preflight[$file] = ['definition' => $definition, 'changed' => $current !== $this->store->render($definition)];
        }
        $changed = [];
        foreach ($preflight as $file => $state) {
            if (!$state['changed']) continue;
            $changed[] = ltrim(substr($file, strlen($root)), '/');
            if ($apply) $this->store->update($file, static fn(array $definition): array => $definition);
        }
        return ['ok' => $changed === [], 'checked' => count($files), 'changed' => $changed];
    }
}
