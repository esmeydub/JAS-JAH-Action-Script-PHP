<?php

declare(strict_types=1);

namespace Jah\Http;

use Jah\Security\SalkGuard;
use RuntimeException;

/**
 * JahTransport
 *
 * Transporte público para JAS.
 * Entrada:
 * - GET query string
 * - POST application/x-www-form-urlencoded / multipart
 * - POST payload PHP serializado (compatibilidad JAS heredada)
 *
 * Salida:
 * - text/plain legible para hackathon.
 */
final class JahTransport
{
    public static function decodeRequest(int $maxBytes = 1048576): array
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > $maxBytes) {
            throw new RuntimeException('JAH payload exceeds maximum allowed size');
        }

        if ($method !== 'POST') {
            if (strlen((string)($_SERVER['QUERY_STRING'] ?? '')) > $maxBytes) {
                throw new RuntimeException('JAH query exceeds maximum allowed size');
            }
            return $_GET;
        }

        if ($_POST !== []) {
            if (strlen(http_build_query($_POST)) > $maxBytes) {
                throw new RuntimeException('JAH payload exceeds maximum allowed size');
            }
            return $_POST;
        }

        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        if (strlen($raw) > $maxBytes) {
            throw new RuntimeException('JAH payload exceeds maximum allowed size');
        }

        $decoded = @unserialize($raw, ['allowed_classes' => false]);
        if (is_array($decoded)) {
            return $decoded;
        }

        parse_str($raw, $parsed);
        return is_array($parsed) ? $parsed : [];
    }

    public static function encodePublic(array $payload, ?SalkGuard $salk = null): string
    {
        if ($salk !== null) {
            $payload = $salk->maskSecrets($payload);
        }

        $lines = ['JAH_RESPONSE'];
        self::renderLines($payload, $lines);
        return implode("\n", $lines) . "\n";
    }

    public static function respond(array $payload, ?SalkGuard $salk = null, ?int $status = null): void
    {
        if ($status !== null) {
            http_response_code($status);
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo self::encodePublic($payload, $salk);
    }

    private static function renderLines(mixed $value, array &$lines, string $prefix = ''): void
    {
        if (is_array($value)) {
            if ($value === []) {
                $lines[] = ($prefix !== '' ? $prefix : 'data') . ': []';
                return;
            }

            foreach ($value as $key => $item) {
                $path = $prefix === '' ? (string)$key : $prefix . '.' . (string)$key;
                if (is_array($item)) {
                    self::renderLines($item, $lines, $path);
                } else {
                    $lines[] = $path . ': ' . self::scalarToText($item);
                }
            }
            return;
        }

        $lines[] = ($prefix !== '' ? $prefix : 'data') . ': ' . self::scalarToText($value);
    }

    private static function scalarToText(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        return str_replace(
            ["\\", "\r", "\n"],
            ["\\\\", '\\r', '\\n'],
            (string)$value
        );
    }
}
