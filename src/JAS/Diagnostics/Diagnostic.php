<?php

declare(strict_types=1);

namespace Jah\JAS\Diagnostics;

use InvalidArgumentException;

final readonly class Diagnostic
{
    /** @param array<string,mixed> $context */
    public function __construct(
        public string $id,
        public string $code,
        public string $severity,
        public string $title,
        public string $message,
        public string $component,
        public ?string $file,
        public ?int $line,
        public array $context,
        public ?string $suggestion,
        public string $occurredAt,
        public string $status = 'rejected',
    ) {
        if (preg_match('/^JAS-\d{8}-[A-F0-9]{12}$/', $id) !== 1) throw new InvalidArgumentException('diagnostic_id_invalid');
        if (!DiagnosticCode::valid($code)) throw new InvalidArgumentException('diagnostic_code_invalid');
        if (!in_array($severity, ['error', 'warning', 'info'], true)) throw new InvalidArgumentException('diagnostic_severity_invalid');
        if ($title === '' || strlen($title) > 160 || $message === '' || strlen($message) > 1_024) throw new InvalidArgumentException('diagnostic_text_invalid');
        if (preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{1,127}$/', $component) !== 1) throw new InvalidArgumentException('diagnostic_component_invalid');
        if ($line !== null && $line < 1) throw new InvalidArgumentException('diagnostic_line_invalid');
        if (!in_array($status, ['rejected', 'resolved', 'acknowledged'], true)) throw new InvalidArgumentException('diagnostic_status_invalid');
        if (strtotime($occurredAt) === false) throw new InvalidArgumentException('diagnostic_time_invalid');
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $record): self
    {
        return new self(
            (string) ($record['id'] ?? ''), (string) ($record['code'] ?? ''),
            (string) ($record['severity'] ?? ''), (string) ($record['title'] ?? ''),
            (string) ($record['message'] ?? ''), (string) ($record['component'] ?? ''),
            isset($record['file']) ? (string) $record['file'] : null,
            isset($record['line']) ? (int) $record['line'] : null,
            is_array($record['context'] ?? null) ? $record['context'] : [],
            isset($record['suggestion']) ? (string) $record['suggestion'] : null,
            (string) ($record['occurredAt'] ?? ''), (string) ($record['status'] ?? 'rejected'),
        );
    }
}
