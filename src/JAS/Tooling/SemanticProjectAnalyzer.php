<?php

declare(strict_types=1);

namespace Jah\JAS\Tooling;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

final class SemanticProjectAnalyzer
{
    public function __construct(private readonly PhpNamespaceIndex $index = new PhpNamespaceIndex()) {}

    /** @return list<array{code:string,file:string,line:int,message:string}> */
    public function analyze(string $root): array
    {
        $diagnostics = [];
        $symbols = [];
        $imports = [];
        $app = $root . '/app';
        if (is_dir($app)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                if (!$entry->isFile() || $entry->getExtension() !== 'php') continue;
                $source = file_get_contents($entry->getPathname());
                if (!is_string($source)) continue;
                $relative = ltrim(substr($entry->getPathname(), strlen($root)), '/');
                $result = $this->index->scan($source, $relative);
                foreach ($result['symbols'] as $symbol) {
                    if (isset($symbols[$symbol['name']])) {
                        $diagnostics[] = $this->diagnostic('JAS030', $relative, $symbol['line'], 'Duplicate symbol: ' . $symbol['name']);
                    } else {
                        $symbols[$symbol['name']] = $symbol;
                    }
                }
                array_push($imports, ...$result['imports']);
            }
        }

        [$dependencies, $domainDiagnostics] = $this->domainDependencies($root);
        array_push($diagnostics, ...$domainDiagnostics);
        foreach ($symbols as $name => $symbol) {
            if (!str_starts_with($name, 'App\\')) continue;
            $expected = 'app/' . str_replace('\\', '/', substr($name, 4)) . '.php';
            if ($symbol['file'] !== $expected) {
                $diagnostics[] = $this->diagnostic('JAS032', $symbol['file'], $symbol['line'], "PSR-4 path mismatch; expected {$expected}");
            }
        }
        foreach ($imports as $import) {
            if (!isset($symbols[$import['name']])) {
                $diagnostics[] = $this->diagnostic('JAS031', $import['file'], $import['line'], 'Unresolved internal symbol: ' . $import['name']);
                continue;
            }
            $caller = $this->domainFromFile($import['file']);
            $target = $this->domainFromSymbol($import['name']);
            if ($caller !== null && $target !== null && $caller !== $target && !in_array($target, $dependencies[$caller] ?? [], true)) {
                $diagnostics[] = $this->diagnostic('JAS040', $import['file'], $import['line'], "Cross-domain dependency not declared: {$caller} -> {$target}");
            }
        }

        try {
            (new GeneratedApplicationLoader())->load($root, 'JAS Semantic Analysis')->validate();
        } catch (Throwable $error) {
            $diagnostics[] = $this->diagnostic('JAS050', 'app/application.php', 1, 'Invalid application graph: ' . $error->getMessage());
        }
        return $diagnostics;
    }

    /** @return array{array<string,list<string>>,list<array{code:string,file:string,line:int,message:string}>} */
    private function domainDependencies(string $root): array
    {
        $dependencies = [];
        $diagnostics = [];
        $reader = new PhpDefinitionReader();
        foreach (glob($root . '/app/Domains/*.php') ?: [] as $file) {
            try {
                $definition = $reader->read($file);
                if (!isset($definition['name'], $definition['dependencies']) || !is_string($definition['name']) || !is_array($definition['dependencies'])) continue;
                $dependencies[$definition['name']] = $definition['dependencies'];
            } catch (Throwable $error) {
                $relative = ltrim(substr($file, strlen($root)), '/');
                $diagnostics[] = $this->diagnostic('JAS050', $relative, 1, 'Invalid domain definition: ' . $error->getMessage());
            }
        }
        return [$dependencies, $diagnostics];
    }

    private function domainFromFile(string $file): ?string
    {
        return preg_match('#^app/Domains/([A-Z][A-Za-z0-9]*)/#', $file, $match) === 1 ? $match[1] : null;
    }

    private function domainFromSymbol(string $symbol): ?string
    {
        return preg_match('/^App\\\\Domains\\\\([A-Z][A-Za-z0-9]*)\\\\/', $symbol, $match) === 1 ? $match[1] : null;
    }

    private function diagnostic(string $code, string $file, int $line, string $message): array
    {
        return ['code' => $code, 'file' => $file, 'line' => $line, 'message' => $message];
    }
}
