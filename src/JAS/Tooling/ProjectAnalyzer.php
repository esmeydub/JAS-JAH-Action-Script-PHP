<?php

declare(strict_types=1);

namespace Jah\JAS\Tooling;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class ProjectAnalyzer
{
    /** @return array{ok:bool,files:int,diagnostics:list<array{code:string,file:string,line:int,message:string}>} */
    public function analyze(string $project): array
    {
        $root = realpath($project);
        if ($root === false || !is_dir($root)) throw new RuntimeException('analyzer_project_invalid');
        $diagnostics = []; $files = 0;
        foreach (['app/Actions', 'app/Domains', 'app/Events', 'app/Types', 'app/Web', 'config', 'public', 'tests'] as $required) {
            if (!is_dir($root . '/' . $required)) $diagnostics[] = $this->diagnostic('JAS001', $required, 1, 'Required project directory is missing');
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->getExtension() !== 'php') continue;
            $files++; $path = $entry->getPathname(); $relative = ltrim(substr($path, strlen($root)), '/');
            $source = file_get_contents($path);
            if (!is_string($source)) { $diagnostics[] = $this->diagnostic('JAS002', $relative, 1, 'PHP source cannot be read'); continue; }
            if (preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)/', $source) !== 1) $diagnostics[] = $this->diagnostic('JAS003', $relative, 1, 'strict_types=1 is required');
            $tokens = token_get_all($source);
            foreach ($tokens as $index => $token) {
                if (!is_array($token)) continue;
                [$kind, $text, $line] = $token;
                if ($kind === T_EVAL) $diagnostics[] = $this->diagnostic('JAS010', $relative, $line, 'Forbidden language construct: eval');
                if ($kind === T_STRING && in_array(strtolower($text), ['eval', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen'], true)) {
                    $diagnostics[] = $this->diagnostic('JAS010', $relative, $line, "Forbidden function: {$text}");
                }
                if ($kind === T_STRING && in_array(strtolower($text), ['json_encode', 'json_decode'], true)) {
                    $diagnostics[] = $this->diagnostic('JAS011', $relative, $line, 'JSON is forbidden by this JAS project policy');
                }
                if ($kind === T_VARIABLE && in_array($text, ['$_GET', '$_POST', '$_REQUEST', '$_FILES', '$_COOKIE', '$_SERVER'], true)
                    && !str_starts_with($relative, 'app/Web/') && !str_starts_with($relative, 'public/')) {
                    $diagnostics[] = $this->diagnostic('JAS012', $relative, $line, 'HTTP superglobals are only allowed at the web boundary');
                }
            }
            foreach (preg_split('/\R/', $source) ?: [] as $line => $text) {
                if (preg_match('/(?:api[_-]?key|password|secret|token)\s*[=:>]\s*[\'\"][^\'\"]{8,}[\'\"]/i', $text)) {
                    $diagnostics[] = $this->diagnostic('JAS020', $relative, $line + 1, 'Possible hardcoded secret');
                }
            }
        }
        usort($diagnostics, static fn(array $a, array $b): int => [$a['file'], $a['line'], $a['code']] <=> [$b['file'], $b['line'], $b['code']]);
        return ['ok' => $diagnostics === [], 'files' => $files, 'diagnostics' => $diagnostics];
    }

    private function diagnostic(string $code, string $file, int $line, string $message): array
    {
        return ['code' => $code, 'file' => $file, 'line' => $line, 'message' => $message];
    }
}
