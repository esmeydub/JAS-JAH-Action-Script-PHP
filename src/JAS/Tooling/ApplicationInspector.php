<?php

declare(strict_types=1);

namespace Jah\JAS\Tooling;

use Jah\JAS\Definition\JasApplication;
use RuntimeException;
use Throwable;

final class ApplicationInspector
{
    public function project(string $directory): JasApplication
    {
        return (new GeneratedApplicationLoader())->load($directory, $this->projectName($directory))->validate();
    }

    public function load(string $file): JasApplication
    {
        $path = realpath($file);
        if ($path === false || !is_file($path) || pathinfo($path, PATHINFO_EXTENSION) !== 'php') throw new RuntimeException('application_definition_invalid');
        $application = require $path;
        if (!$application instanceof JasApplication) throw new RuntimeException('application_definition_must_return_jas_application');
        return $application->validate();
    }

    public function markdown(JasApplication $application): string
    {
        $manifest = $application->describe();
        $lines = [
            '# ' . $manifest['name'], '',
            'Documentación técnica generada por JAS. No editar manualmente.', '',
            'Fingerprint: `' . $manifest['fingerprint'] . '`', '',
            '## Resumen', '',
            '- Dominios: ' . count($manifest['domains']),
            '- Tipos: ' . count($manifest['types']),
            '- Acciones: ' . count($manifest['contracts']),
            '- Eventos: ' . count($manifest['events']), '',
            '## Diagrama de dominios', '', '```mermaid',
            ...explode("\n", rtrim($this->domainDiagram($application))),
            '```', '', '## Diagrama de contratos', '', '```mermaid',
            ...explode("\n", rtrim($this->contractDiagram($application))),
            '```', '', '## Dominios', '',
        ];
        foreach ($manifest['domains'] as $name => $domain) {
            $dependencies = $domain['dependencies'] === [] ? 'ninguna' : implode(', ', $domain['dependencies']);
            $lines[] = "- **{$name}** (`{$domain['prefix']}.*`) — dependencias: {$dependencies}";
        }
        $lines[] = ''; $lines[] = '## Tipos'; $lines[] = '';
        foreach ($manifest['types'] as $name => $definition) {
            $lines[] = "### {$name}"; $lines[] = '';
            foreach ($definition['fields'] as $field => $type) $lines[] = "- `{$field}`: `{$type}`";
            $lines[] = '';
        }
        $lines[] = '## Acciones'; $lines[] = '';
        foreach ($manifest['contracts'] as $name => $contract) {
            $lines[] = "### `{$name}`"; $lines[] = '';
            $lines[] = "- Dominio: {$contract['domain']}";
            $lines[] = "- Entrada: `{$contract['input']}`";
            $lines[] = "- Salida: `{$contract['output']}`";
            $lines[] = "- Capacidad: `{$contract['capability']}`";
            $lines[] = '- Auditoría: ' . ($contract['audit'] ? 'sí' : 'no');
            $lines[] = '- Idempotente: ' . ($contract['idempotent'] ? 'sí' : 'no');
            if ($contract['emits']) $lines[] = "- Emite: `{$contract['emits']}`";
            $lines[] = '';
        }
        $lines[] = '## Eventos'; $lines[] = '';
        foreach ($manifest['events'] as $event) $lines[] = "- `{$event['name']}@{$event['version']}` → `{$event['payload']}`";
        return implode("\n", $lines) . "\n";
    }

    public function mermaid(JasApplication $application): string
    {
        return "%% JAS generated diagrams\n" . $this->domainDiagram($application) . "\n" . $this->contractDiagram($application);
    }

    public function writeMarkdown(JasApplication $application, string $target): void
    {
        $this->writeArtifact($target, $this->markdown($application), 'md');
    }

    public function writeMermaid(JasApplication $application, string $target): void
    {
        $this->writeArtifact($target, $this->mermaid($application), 'mmd');
    }

    private function domainDiagram(JasApplication $application): string
    {
        $manifest = $application->describe();
        $lines = ['flowchart LR'];
        foreach ($manifest['domains'] as $name => $domain) {
            $lines[] = '    D_' . $name . '["' . $name . ' · ' . $domain['prefix'] . '.*"]';
        }
        foreach ($manifest['domains'] as $name => $domain) {
            foreach ($domain['dependencies'] as $dependency) $lines[] = '    D_' . $name . ' --> D_' . $dependency;
        }
        if (count($lines) === 1) $lines[] = '    Empty["Sin dominios"]';
        return implode("\n", $lines) . "\n";
    }

    private function contractDiagram(JasApplication $application): string
    {
        $manifest = $application->describe();
        $lines = ['flowchart LR'];
        $index = 0;
        foreach ($manifest['contracts'] as $name => $contract) {
            $node = 'A' . $index++;
            $input = 'I_' . $contract['input'];
            $output = 'O_' . $contract['output'];
            $lines[] = '    ' . $input . '(["' . $contract['input'] . '"]) --> ' . $node . '["' . $name . '"]';
            $lines[] = '    ' . $node . ' --> ' . $output . '(["' . $contract['output'] . '"])';
            if (is_string($contract['emits']) && $contract['emits'] !== '') {
                $lines[] = '    ' . $node . ' -. emite .-> E' . ($index - 1) . '(["' . $contract['emits'] . '"])';
            }
        }
        if ($index === 0) $lines[] = '    Empty["Sin acciones"]';
        return implode("\n", $lines) . "\n";
    }

    private function projectName(string $directory): string
    {
        $path = realpath($directory);
        if ($path === false || !is_dir($path)) throw new RuntimeException('application_project_invalid');
        $application = file_get_contents($path . '/app/application.php');
        if (!is_string($application) || preg_match("/->load\\(dirname\\(__DIR__\\), '([A-Z][A-Za-z0-9 _-]{2,127})'\\)/", $application, $match) !== 1) {
            throw new RuntimeException('application_project_name_invalid');
        }
        return $match[1];
    }

    private function writeArtifact(string $target, string $content, string $extension): void
    {
        if ($target === '' || str_contains($target, "\0") || is_link($target)
            || strtolower(pathinfo($target, PATHINFO_EXTENSION)) !== $extension) {
            throw new RuntimeException('application_docs_target_invalid');
        }
        $directory = realpath(dirname($target));
        if ($directory === false || !is_dir($directory)) throw new RuntimeException('application_docs_target_invalid');
        $path = $directory . '/' . basename($target);
        $temporary = $directory . '/.' . basename($target) . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            $handle = @fopen($temporary, 'xb');
            if ($handle === false) throw new RuntimeException('application_docs_write_failed');
            try {
                @chmod($temporary, 0600);
                $offset = 0;
                while ($offset < strlen($content)) {
                    $written = fwrite($handle, substr($content, $offset));
                    if ($written === false || $written === 0) throw new RuntimeException('application_docs_write_failed');
                    $offset += $written;
                }
                if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) throw new RuntimeException('application_docs_write_failed');
            } finally {
                fclose($handle);
            }
            if (is_link($path) || !rename($temporary, $path)) throw new RuntimeException('application_docs_write_failed');
        } catch (Throwable $error) {
            @unlink($temporary);
            throw $error;
        }
    }
}
