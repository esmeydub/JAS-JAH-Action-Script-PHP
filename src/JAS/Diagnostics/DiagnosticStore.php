<?php

declare(strict_types=1);

namespace Jah\JAS\Diagnostics;

use Jah\DataCore\PhpSerializer;
use RuntimeException;

final class DiagnosticStore
{
    private string $journal;
    private readonly DiagnosticSanitizer $sanitizer;

    public function __construct(string $directory, ?DiagnosticSanitizer $sanitizer = null)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('diagnostic_store_directory_failed');
        if (is_link($directory)) throw new RuntimeException('diagnostic_store_symlink_forbidden');
        $this->journal = rtrim($directory, '/') . '/incidents.jasb';
        if (is_link($this->journal)) throw new RuntimeException('diagnostic_store_symlink_forbidden');
        $this->sanitizer = $sanitizer ?? new DiagnosticSanitizer();
    }

    public function append(Diagnostic $diagnostic): Diagnostic
    {
        if (is_link($this->journal)) throw new RuntimeException('diagnostic_store_symlink_forbidden');
        $diagnostic = $this->sanitizer->sanitize($diagnostic);
        $line = PhpSerializer::encode($diagnostic->toArray()) . "\n";
        if (strlen($line) > 1_048_576 || file_put_contents($this->journal, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new RuntimeException('diagnostic_store_write_failed');
        }
        @chmod($this->journal, 0600);
        return $diagnostic;
    }

    /** @return list<Diagnostic> */
    public function all(): array
    {
        if (!is_file($this->journal)) return [];
        $records = [];
        foreach (file($this->journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = PhpSerializer::decode($line);
            if (!is_array($decoded)) continue;
            try { $records[] = Diagnostic::fromArray($decoded); } catch (\Throwable) { continue; }
        }
        return $records;
    }

    public function last(): ?Diagnostic
    {
        $all = $this->all();
        return $all === [] ? null : $all[array_key_last($all)];
    }

    public function find(string $id): ?Diagnostic
    {
        foreach (array_reverse($this->all()) as $diagnostic) if (hash_equals($diagnostic->id, $id)) return $diagnostic;
        return null;
    }

    /** @return list<Diagnostic> */
    public function byCode(string $code): array
    {
        return array_values(array_filter($this->all(), static fn(Diagnostic $item): bool => $item->code === $code));
    }

    /** @return array<string,int> */
    public function summary(): array
    {
        $counts = [];
        foreach ($this->all() as $item) $counts[$item->code] = ($counts[$item->code] ?? 0) + 1;
        ksort($counts);
        return $counts;
    }
}
