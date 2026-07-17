<?php

declare(strict_types=1);

namespace Jah\JAS\Tooling;

use Jah\JAS\Definition\JasApplication;
use RuntimeException;

final class ApplicationInspector
{
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
        $lines = ['# ' . $manifest['name'], '', 'Fingerprint: `' . $manifest['fingerprint'] . '`', '', '## Dominios', ''];
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

    public function writeMarkdown(JasApplication $application, string $target): void
    {
        if ($target === '' || str_contains($target, "\0")) throw new RuntimeException('application_docs_target_invalid');
        $content = $this->markdown($application);
        $temporary = $target . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content) || !rename($temporary, $target)) throw new RuntimeException('application_docs_write_failed');
    }
}
