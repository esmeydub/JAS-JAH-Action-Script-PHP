<?php

declare(strict_types=1);

namespace Jah\JAS\Diagnostics;

final class DiagnosticSanitizer
{
    private const SENSITIVE = '/(?:password|passwd|cookie|authorization|token|secret|api[_-]?key|private[_-]?key|session|credential)/i';

    public function sanitize(Diagnostic $diagnostic): Diagnostic
    {
        return new Diagnostic(
            $diagnostic->id, $diagnostic->code, $diagnostic->severity,
            $this->text($diagnostic->title, 160), $this->text($diagnostic->message, 1_024),
            $diagnostic->component, $this->file($diagnostic->file), $diagnostic->line,
            $this->context($diagnostic->context),
            $diagnostic->suggestion === null ? null : $this->text($diagnostic->suggestion, 1_024),
            $diagnostic->occurredAt, $diagnostic->status,
        );
    }

    private function context(array $context, int $depth = 0): array
    {
        if ($depth > 3) return ['truncated' => true];
        $clean = [];
        foreach (array_slice($context, 0, 64, true) as $key => $value) {
            $name = is_string($key) ? substr($key, 0, 128) : (string) $key;
            if (preg_match(self::SENSITIVE, $name) === 1) { $clean[$name] = '[REDACTED]'; continue; }
            if (is_array($value)) { $clean[$name] = $this->context($value, $depth + 1); continue; }
            if (is_string($value)) { $clean[$name] = preg_match(self::SENSITIVE, $value) === 1 ? '[REDACTED]' : $this->text($value, 512); continue; }
            $clean[$name] = is_int($value) || is_float($value) || is_bool($value) || $value === null ? $value : '[UNSUPPORTED]';
        }
        return $clean;
    }

    private function file(?string $file): ?string
    {
        if ($file === null) return null;
        $file = str_replace('\\', '/', $file);
        if (str_starts_with($file, '/') || preg_match('/^[A-Za-z]:\//', $file) === 1) return basename($file);
        return ltrim(substr($file, 0, 512), '/');
    }

    private function text(string $value, int $limit): string
    {
        $value = str_replace(["\0", "\r"], ['', ''], $value);
        return substr($value, 0, $limit);
    }
}
