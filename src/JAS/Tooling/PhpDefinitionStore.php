<?php

declare(strict_types=1);

namespace Jah\JAS\Tooling;

use RuntimeException;

final class PhpDefinitionStore
{
    public function __construct(private readonly PhpDefinitionReader $reader = new PhpDefinitionReader()) {}

    public function read(string $file): array
    {
        return $this->reader->read($this->safeFile($file));
    }

    /** @return array{changed:bool,hash:string,definition:array} */
    public function update(string $file, callable $transform): array
    {
        $path = $this->safeFile($file);
        $directory = dirname($path);
        $lock = fopen($directory . '/.jas-definitions.lock', 'c+b');
        if ($lock === false) throw new RuntimeException('definition_lock_open_failed');
        @chmod($directory . '/.jas-definitions.lock', 0600);
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('definition_lock_failed');
            $path = $this->safeFile($path);
            $current = $this->reader->read($path);
            $next = $transform($current);
            if (!is_array($next) || array_is_list($next)) throw new RuntimeException('definition_update_invalid');
            $content = $this->render($next);
            $existing = file_get_contents($path);
            if (!is_string($existing)) throw new RuntimeException('definition_read_failed');
            if (hash_equals(hash('sha256', $existing), hash('sha256', $content))) {
                return ['changed' => false, 'hash' => hash('sha256', $content), 'definition' => $next];
            }
            $temporary = $directory . '/.' . basename($path) . '.' . bin2hex(random_bytes(8)) . '.tmp';
            try {
                $handle = @fopen($temporary, 'xb');
                if ($handle === false) throw new RuntimeException('definition_temporary_create_failed');
                try {
                    @chmod($temporary, fileperms($path) & 0777 ?: 0600);
                    $offset = 0;
                    $length = strlen($content);
                    while ($offset < $length) {
                        $written = fwrite($handle, substr($content, $offset));
                        if ($written === false || $written === 0) throw new RuntimeException('definition_write_failed');
                        $offset += $written;
                    }
                    if (!fflush($handle)) throw new RuntimeException('definition_flush_failed');
                    if (function_exists('fsync') && !fsync($handle)) throw new RuntimeException('definition_sync_failed');
                } finally {
                    fclose($handle);
                }
                $verified = $this->reader->read($temporary);
                if ($verified !== $next) throw new RuntimeException('definition_verification_failed');
                if (!rename($temporary, $path)) throw new RuntimeException('definition_replace_failed');
            } finally {
                if (is_file($temporary)) @unlink($temporary);
            }
            return ['changed' => true, 'hash' => hash('sha256', $content), 'definition' => $next];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function render(array $definition): string
    {
        if (array_is_list($definition)) throw new RuntimeException('definition_root_invalid');
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn " . $this->literal($definition, 0) . ";\n";
    }

    private function safeFile(string $file): string
    {
        if ($file === '' || str_contains($file, "\0") || is_link($file)) throw new RuntimeException('definition_path_invalid');
        $path = realpath($file);
        if ($path === false || !is_file($path) || pathinfo($path, PATHINFO_EXTENSION) !== 'php' || is_link($path)) {
            throw new RuntimeException('definition_path_invalid');
        }
        $directory = realpath(dirname($path));
        if ($directory === false || dirname($path) !== $directory || preg_match('/^[A-Z][A-Za-z0-9_]*\.php$/', basename($path)) !== 1) {
            throw new RuntimeException('definition_path_invalid');
        }
        return $path;
    }

    private function literal(mixed $value, int $depth): string
    {
        if ($depth > 16) throw new RuntimeException('definition_too_deep');
        if (is_string($value)) return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
        if (is_int($value)) return (string) $value;
        if (is_bool($value)) return $value ? 'true' : 'false';
        if ($value === null) return 'null';
        if (!is_array($value)) throw new RuntimeException('definition_value_invalid');
        if ($value === []) return '[]';
        $indent = str_repeat('    ', $depth);
        $childIndent = str_repeat('    ', $depth + 1);
        $list = array_is_list($value);
        $lines = [];
        foreach ($value as $key => $item) {
            $prefix = $list ? '' : $this->literal($key, $depth + 1) . ' => ';
            $lines[] = $childIndent . $prefix . $this->literal($item, $depth + 1) . ',';
        }
        return "[\n" . implode("\n", $lines) . "\n{$indent}]";
    }
}
