<?php

declare(strict_types=1);

namespace Jah\JAS\Tooling;

use RuntimeException;
use Throwable;

final class AtomicWorkspaceEditor
{
    /**
     * @param list<array{file:string,offset:int,length:int,expected:string,replacement:string,hash:string}> $edits
     * @param list<array{from:string,to:string}> $renames
     */
    public function apply(string $project, array $edits, array $renames = []): void
    {
        $root = realpath($project);
        if ($root === false || !is_dir($root) || $edits === [] || count($edits) > 1_024) {
            throw new RuntimeException('workspace_edit_invalid');
        }
        $lock = fopen($root . '/.jas-language.lock', 'c+b');
        if ($lock === false) throw new RuntimeException('workspace_edit_lock_failed');
        @chmod($root . '/.jas-language.lock', 0600);
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('workspace_edit_lock_failed');
            $this->applyLocked($root, $edits, $renames);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param list<array{file:string,offset:int,length:int,expected:string,replacement:string,hash:string}> $edits
     * @param list<array{from:string,to:string}> $renames
     */
    private function applyLocked(string $root, array $edits, array $renames): void
    {
        $grouped = [];
        foreach ($edits as $edit) {
            $path = $this->safeDefinition($root, $edit['file']);
            $grouped[$path][] = $edit;
        }
        ksort($grouped);
        $targets = [];
        foreach ($renames as $rename) {
            $from = $this->safeDefinition($root, $rename['from']);
            if (!isset($grouped[$from]) || isset($targets[$from])) throw new RuntimeException('workspace_rename_invalid');
            $target = $this->safeTarget($root, $rename['to']);
            if (in_array($target, $targets, true)) throw new RuntimeException('workspace_rename_conflict');
            $targets[$from] = $target;
        }
        $prepared = [];
        try {
            foreach ($grouped as $path => $fileEdits) {
                $source = file_get_contents($path);
                if (!is_string($source) || !hash_equals($fileEdits[0]['hash'], hash('sha256', $source))) {
                    throw new RuntimeException('workspace_edit_stale');
                }
                usort($fileEdits, static fn(array $left, array $right): int => $right['offset'] <=> $left['offset']);
                $lastOffset = strlen($source);
                foreach ($fileEdits as $edit) {
                    $offset = $edit['offset'];
                    $length = $edit['length'];
                    if ($offset < 0 || $length < 1 || $offset + $length > $lastOffset
                        || substr($source, $offset, $length) !== $edit['expected']) {
                        throw new RuntimeException('workspace_edit_conflict');
                    }
                    $source = substr_replace($source, $edit['replacement'], $offset, $length);
                    $lastOffset = $offset;
                }
                $temporary = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(8)) . '.jas-language';
                $this->writeExclusive($temporary, $source, fileperms($path) & 0777 ?: 0600);
                (new PhpDefinitionReader())->read($temporary);
                $prepared[$path] = ['temporary' => $temporary, 'backup' => '', 'target' => $targets[$path] ?? $path, 'published' => false];
            }

            foreach ($prepared as $path => &$entry) {
                $backup = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(8)) . '.jas-backup';
                if ($entry['target'] !== $path && (file_exists($entry['target']) || is_link($entry['target']))) {
                    throw new RuntimeException('workspace_rename_conflict');
                }
                if (!rename($path, $backup)) throw new RuntimeException('workspace_edit_backup_failed');
                $entry['backup'] = $backup;
                if (!rename($entry['temporary'], $entry['target'])) throw new RuntimeException('workspace_edit_replace_failed');
                $entry['temporary'] = '';
                $entry['published'] = true;
            }
            unset($entry);
            foreach ($prepared as $entry) @unlink($entry['backup']);
        } catch (Throwable $error) {
            unset($entry);
            foreach (array_reverse($prepared, true) as $path => $entry) {
                if ($entry['published'] && is_file($entry['target'])) @unlink($entry['target']);
                if ($entry['backup'] !== '' && is_file($entry['backup'])) @rename($entry['backup'], $path);
                if ($entry['temporary'] !== '' && is_file($entry['temporary'])) @unlink($entry['temporary']);
            }
            throw $error;
        }
    }

    private function safeTarget(string $root, string $relative): string
    {
        if ($relative === '' || str_contains($relative, "\0") || str_starts_with($relative, '/') || str_contains($relative, '..')
            || preg_match('/^[A-Z][A-Za-z0-9_]*\.php$/', basename($relative)) !== 1) {
            throw new RuntimeException('workspace_rename_path_invalid');
        }
        $directory = realpath($root . '/' . dirname($relative));
        $app = realpath($root . '/app');
        if ($directory === false || $app === false || !str_starts_with($directory, $app . '/')) {
            throw new RuntimeException('workspace_rename_path_invalid');
        }
        return $directory . '/' . basename($relative);
    }

    private function safeDefinition(string $root, string $relative): string
    {
        if ($relative === '' || str_contains($relative, "\0") || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            throw new RuntimeException('workspace_edit_path_invalid');
        }
        $path = realpath($root . '/' . $relative);
        $app = realpath($root . '/app');
        if ($path === false || $app === false || !str_starts_with($path, $app . '/') || !is_file($path)
            || is_link($path) || pathinfo($path, PATHINFO_EXTENSION) !== 'php') {
            throw new RuntimeException('workspace_edit_path_invalid');
        }
        return $path;
    }

    private function writeExclusive(string $path, string $content, int $mode): void
    {
        $handle = @fopen($path, 'xb');
        if ($handle === false) throw new RuntimeException('workspace_edit_prepare_failed');
        try {
            @chmod($path, $mode);
            $offset = 0;
            while ($offset < strlen($content)) {
                $written = fwrite($handle, substr($content, $offset));
                if ($written === false || $written === 0) throw new RuntimeException('workspace_edit_write_failed');
                $offset += $written;
            }
            if (!fflush($handle)) throw new RuntimeException('workspace_edit_flush_failed');
            if (function_exists('fsync') && !fsync($handle)) throw new RuntimeException('workspace_edit_sync_failed');
        } finally {
            fclose($handle);
        }
    }
}
