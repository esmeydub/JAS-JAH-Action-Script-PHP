<?php

declare(strict_types=1);

namespace Jah\JAS\Tooling;

use RuntimeException;
use Throwable;

/** @internal Semantic index and safe editing implementation. */
final class JasLanguageIndex
{
    public function __construct(private readonly AtomicWorkspaceEditor $editor = new AtomicWorkspaceEditor()) {}

    /** @return array{ok:bool,files:int,diagnostics:list<array{code:string,file:string,line:int,message:string}>} */
    public function diagnostics(string $project): array
    {
        return (new ProjectAnalyzer())->analyze($project);
    }

    /** @return array{kind:string,name:string,detail:string,location:array{file:string,line:int,column:int,length:int,role:string}}|null */
    public function hover(string $project, string $file, int $line, int $column): ?array
    {
        $index = $this->index($project);
        $occurrence = $this->occurrenceAt($index, $file, $line, $column);
        if ($occurrence === null) return null;
        $symbol = $index['symbols'][$occurrence['symbol']];
        return ['kind' => $symbol['kind'], 'name' => $symbol['name'], 'detail' => $symbol['detail'], 'location' => $this->location($occurrence)];
    }

    /** @return array{file:string,line:int,column:int,length:int,role:string}|null */
    public function definition(string $project, string $file, int $line, int $column): ?array
    {
        $index = $this->index($project);
        $occurrence = $this->occurrenceAt($index, $file, $line, $column);
        if ($occurrence === null) return null;
        $occurrences = $index['symbols'][$occurrence['symbol']]['occurrences'];
        foreach ($occurrences as $candidate) if ($candidate['role'] === 'declaration') return $this->location($candidate);
        return isset($occurrences[0]) ? $this->location($occurrences[0]) : null;
    }

    /** @return list<array{file:string,line:int,column:int,length:int,role:string}> */
    public function references(string $project, string $file, int $line, int $column): array
    {
        $index = $this->index($project);
        $occurrence = $this->occurrenceAt($index, $file, $line, $column);
        if ($occurrence === null) return [];
        return array_map(fn(array $item): array => $this->location($item), $index['symbols'][$occurrence['symbol']]['occurrences']);
    }

    /** @return array{applied:bool,kind:string,previous:string,replacement:string,changes:list<array{file:string,line:int,column:int,length:int,role:string}>,files:list<array{from:string,to:string}>} */
    public function rename(string $project, string $file, int $line, int $column, string $replacement, bool $apply = false): array
    {
        $index = $this->index($project);
        $occurrence = $this->occurrenceAt($index, $file, $line, $column);
        if ($occurrence === null) throw new RuntimeException('language_symbol_not_found');
        $symbol = $index['symbols'][$occurrence['symbol']];
        $this->validateName($symbol['kind'], $replacement);
        $destination = $symbol['kind'] . ':' . $replacement;
        if ($destination !== $occurrence['symbol'] && isset($index['symbols'][$destination])) throw new RuntimeException('language_symbol_conflict');
        $changes = array_map(fn(array $item): array => $this->location($item), $symbol['occurrences']);
        if ($replacement === $symbol['name']) {
            return ['applied' => false, 'kind' => $symbol['kind'], 'previous' => $symbol['name'], 'replacement' => $replacement, 'changes' => [], 'files' => []];
        }
        $renames = $this->physicalRenames($symbol['kind'], $symbol['occurrences'], $replacement);
        if ($apply) {
            $edits = [];
            foreach ($symbol['occurrences'] as $item) {
                $edits[] = [
                    'file' => $item['file'], 'offset' => $item['offset'], 'length' => $item['length'],
                    'expected' => $symbol['name'], 'replacement' => $replacement, 'hash' => $item['hash'],
                ];
            }
            $this->editor->apply($project, $edits, $renames);
        }
        return ['applied' => $apply, 'kind' => $symbol['kind'], 'previous' => $symbol['name'], 'replacement' => $replacement, 'changes' => $changes, 'files' => $renames];
    }

    /** @param list<array{file:string,role:string}> $occurrences @return list<array{from:string,to:string}> */
    private function physicalRenames(string $kind, array $occurrences, string $replacement): array
    {
        if (!in_array($kind, ['type', 'domain', 'action', 'event'], true)) return [];
        $declaration = null;
        foreach ($occurrences as $occurrence) {
            if ($occurrence['role'] === 'declaration') {
                $declaration = $occurrence['file'];
                break;
            }
        }
        if (!is_string($declaration)) return [];
        $stem = match ($kind) {
            'type', 'domain' => $replacement,
            default => str_replace(' ', '', ucwords(str_replace(['.', ':', '-'], ' ', $replacement))),
        };
        if ($kind === 'event') {
            if (preg_match('/V([1-9][0-9]*)\.php$/', $declaration, $match) !== 1) {
                throw new RuntimeException('language_event_file_invalid');
            }
            $stem .= 'V' . $match[1];
        }
        $target = dirname($declaration) . '/' . $stem . '.php';
        return $target === $declaration ? [] : [['from' => $declaration, 'to' => $target]];
    }

    /**
     * @return array{root:string,sources:array<string,string>,symbols:array<string,array{kind:string,name:string,detail:string,occurrences:list<array{symbol:string,file:string,line:int,column:int,offset:int,length:int,role:string,hash:string}>}>,occurrences:list<array{symbol:string,file:string,line:int,column:int,offset:int,length:int,role:string,hash:string}>}
     */
    private function index(string $project): array
    {
        $root = realpath($project);
        if ($root === false || !is_dir($root)) throw new RuntimeException('language_project_invalid');
        $sources = [];
        $records = [];
        foreach (['Domains', 'Types', 'Actions', 'Events'] as $directory) {
            $files = glob($root . '/app/' . $directory . '/*.php') ?: [];
            sort($files);
            foreach ($files as $path) {
                try {
                    $definition = (new PhpDefinitionReader())->read($path);
                } catch (Throwable) {
                    continue;
                }
                $source = file_get_contents($path);
                if (!is_string($source)) continue;
                $relative = ltrim(substr($path, strlen($root)), '/');
                $sources[$relative] = $source;
                $records[] = ['directory' => $directory, 'file' => $relative, 'source' => $source, 'definition' => $definition, 'values' => $this->valueLocations($source)];
            }
        }

        $symbols = [];
        $occurrences = [];
        foreach ($records as $record) {
            $definition = $record['definition'];
            $directory = $record['directory'];
            if ($directory === 'Types') {
                $this->add($symbols, $occurrences, $record, 'type', 'name', 'declaration', $this->typeDetail($definition));
            } elseif ($directory === 'Domains') {
                $this->add($symbols, $occurrences, $record, 'domain', 'name', 'declaration', 'Dominio organizado de aplicación');
            } elseif ($directory === 'Actions') {
                $this->add($symbols, $occurrences, $record, 'action', 'name', 'declaration', $this->actionDetail($definition));
                $this->add($symbols, $occurrences, $record, 'domain', 'domain', 'reference', 'Dominio organizado de aplicación');
                $this->add($symbols, $occurrences, $record, 'type', 'input', 'reference', 'Tipo JAS');
                $this->add($symbols, $occurrences, $record, 'type', 'output', 'reference', 'Tipo JAS');
                $this->add($symbols, $occurrences, $record, 'capability', 'capability', 'reference', 'Capacidad de autorización requerida');
            } elseif ($directory === 'Events') {
                $this->add($symbols, $occurrences, $record, 'event', 'name', 'declaration', $this->eventDetail($definition));
                $this->add($symbols, $occurrences, $record, 'domain', 'domain', 'reference', 'Dominio organizado de aplicación');
                $this->add($symbols, $occurrences, $record, 'type', 'payload', 'reference', 'Tipo JAS');
            }
        }
        foreach ($symbols as &$symbol) {
            usort($symbol['occurrences'], static fn(array $a, array $b): int => [$a['file'], $a['offset']] <=> [$b['file'], $b['offset']]);
            if ($symbol['kind'] === 'capability' && isset($symbol['occurrences'][0])) $symbol['occurrences'][0]['role'] = 'declaration';
        }
        unset($symbol);
        $occurrences = [];
        foreach ($symbols as $symbol) array_push($occurrences, ...$symbol['occurrences']);
        return ['root' => $root, 'sources' => $sources, 'symbols' => $symbols, 'occurrences' => $occurrences];
    }

    /** @param array<string,array{kind:string,name:string,detail:string,occurrences:array}> $symbols @param list<array> $occurrences @param array $record */
    private function add(array &$symbols, array &$occurrences, array $record, string $kind, string $key, string $role, string $detail): void
    {
        $value = $record['definition'][$key] ?? null;
        $location = $record['values'][$key] ?? null;
        if (!is_string($value) || !is_array($location) || $location['value'] !== $value) return;
        $id = $kind . ':' . $value;
        $occurrence = [
            'symbol' => $id, 'file' => $record['file'], 'line' => $location['line'], 'column' => $location['column'],
            'offset' => $location['offset'], 'length' => strlen($value), 'role' => $role, 'hash' => hash('sha256', $record['source']),
        ];
        $symbols[$id] ??= ['kind' => $kind, 'name' => $value, 'detail' => $detail, 'occurrences' => []];
        if ($role === 'declaration' || $symbols[$id]['detail'] === 'Tipo JAS') $symbols[$id]['detail'] = $detail;
        $symbols[$id]['occurrences'][] = $occurrence;
        $occurrences[] = $occurrence;
    }

    /** @return array<string,array{value:string,offset:int,line:int,column:int}> */
    private function valueLocations(string $source): array
    {
        preg_match_all("/'((?:\\\\.|[^'\\\\])*)'\\s*=>\\s*'((?:\\\\.|[^'\\\\])*)'/", $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $locations = [];
        foreach ($matches as $match) {
            $key = $this->decodeLiteral($match[1][0]);
            if (isset($locations[$key])) continue;
            $value = $this->decodeLiteral($match[2][0]);
            $offset = $match[2][1];
            [$line, $column] = $this->lineColumn($source, $offset);
            $locations[$key] = ['value' => $value, 'offset' => $offset, 'line' => $line, 'column' => $column];
        }
        return $locations;
    }

    private function decodeLiteral(string $inner): string
    {
        return str_replace(["\\'", "\\\\"], ["'", "\\"], $inner);
    }

    /** @return array{int,int} */
    private function lineColumn(string $source, int $offset): array
    {
        $before = substr($source, 0, $offset);
        $line = substr_count($before, "\n") + 1;
        $newline = strrpos($before, "\n");
        return [$line, $newline === false ? $offset + 1 : $offset - $newline];
    }

    /** @param array $index @return array|null */
    private function occurrenceAt(array $index, string $file, int $line, int $column): ?array
    {
        if ($line < 1 || $column < 1 || str_contains($file, "\0") || str_contains($file, '..')) throw new RuntimeException('language_position_invalid');
        $root = $index['root'];
        $path = str_starts_with($file, '/') ? realpath($file) : realpath($root . '/' . ltrim($file, '/'));
        if ($path === false || !str_starts_with($path, $root . '/')) throw new RuntimeException('language_file_invalid');
        $relative = ltrim(substr($path, strlen($root)), '/');
        foreach ($index['occurrences'] as $occurrence) {
            if ($occurrence['file'] === $relative && $occurrence['line'] === $line
                && $column >= $occurrence['column'] && $column < $occurrence['column'] + $occurrence['length']) return $occurrence;
        }
        return null;
    }

    /** @param array $occurrence @return array{file:string,line:int,column:int,length:int,role:string} */
    private function location(array $occurrence): array
    {
        return ['file' => $occurrence['file'], 'line' => $occurrence['line'], 'column' => $occurrence['column'], 'length' => $occurrence['length'], 'role' => $occurrence['role']];
    }

    private function validateName(string $kind, string $name): void
    {
        $valid = match ($kind) {
            'type' => preg_match('/^[A-Z][A-Za-z0-9_]{1,127}$/', $name) === 1,
            'domain' => preg_match('/^[A-Z][A-Za-z0-9]{1,63}$/', $name) === 1,
            'action', 'event' => preg_match('/^[a-z][a-z0-9_.:-]{2,255}$/', $name) === 1,
            'capability' => preg_match('/^[a-z][a-z0-9_.:*\\-]{2,255}$/', $name) === 1,
            default => false,
        };
        if (!$valid) throw new RuntimeException('language_rename_invalid');
    }

    private function typeDetail(array $definition): string
    {
        $fields = $definition['fields'] ?? [];
        return 'Tipo JAS estricto con ' . (is_array($fields) ? count($fields) : 0) . ' campo(s)';
    }

    private function actionDetail(array $definition): string
    {
        return 'Acción JAS: ' . (string) ($definition['input'] ?? '?') . ' → ' . (string) ($definition['output'] ?? '?')
            . ' · capacidad ' . (string) ($definition['capability'] ?? '?');
    }

    private function eventDetail(array $definition): string
    {
        return 'Evento JAS v' . (string) ($definition['version'] ?? '?') . ' · payload ' . (string) ($definition['payload'] ?? '?');
    }
}
