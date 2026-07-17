<?php

declare(strict_types=1);

namespace Jah\JAS\Diagnostics;

final class DiagnosticCode
{
    public const HTML_ATTRIBUTE_NOT_ALLOWED = 'JAS-WEB-001';
    public const UNSAFE_HTML_CONTENT = 'JAS-WEB-002';
    public const INPUT_TYPE_MISMATCH = 'JAS-TYPE-001';
    public const OUTPUT_TYPE_MISMATCH = 'JAS-TYPE-002';
    public const ACTION_NOT_REGISTERED = 'JAS-ACT-001';
    public const CAPABILITY_MISSING = 'JAS-CAP-001';
    public const ROUTE_NOT_REGISTERED = 'JAS-ROUTE-001';
    public const STRICT_TYPES_MISSING = 'JAS-PHP-001';
    public const CORE_INTEGRITY_VIOLATION = 'JAS-CORE-001';
    public const UNHANDLED_RUNTIME_ERROR = 'JAS-CORE-002';

    private const ALL = [
        self::HTML_ATTRIBUTE_NOT_ALLOWED,
        self::UNSAFE_HTML_CONTENT,
        self::INPUT_TYPE_MISMATCH,
        self::OUTPUT_TYPE_MISMATCH,
        self::ACTION_NOT_REGISTERED,
        self::CAPABILITY_MISSING,
        self::ROUTE_NOT_REGISTERED,
        self::STRICT_TYPES_MISSING,
        self::CORE_INTEGRITY_VIOLATION,
        self::UNHANDLED_RUNTIME_ERROR,
    ];

    public static function valid(string $code): bool
    {
        return in_array($code, self::ALL, true);
    }

    /** @return list<string> */
    public static function all(): array { return self::ALL; }
}
