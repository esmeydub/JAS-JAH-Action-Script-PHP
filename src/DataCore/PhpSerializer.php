<?php

declare(strict_types=1);

namespace Jah\DataCore;

/**
 * PhpSerializer
 *
 * Serializador interno de JAH DataCore.
 *
 * Objetivo:
 * - Evitar formatos externos como almacenamiento interno del motor.
 * - Mantener PHP puro.
 * - Guardar estructuras PHP en formato line-safe.
 * - Mantener el transporte público fuera de DataCore.
 */
final class PhpSerializer
{
    private const PREFIX = 'JAHPS1:';

    public static function encode(mixed $value, mixed ...$ignored): string
    {
        return self::PREFIX . base64_encode(serialize($value));
    }

    public static function decode(string|false|null $payload, mixed ...$ignored): mixed
    {
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        if (str_starts_with($payload, self::PREFIX)) {
            $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);
            if ($raw === false) {
                return null;
            }

            $decoded = @unserialize($raw, ['allowed_classes' => false]);
            return $decoded === false && $raw !== serialize(false) ? null : $decoded;
        }

        $decoded = @unserialize($payload, ['allowed_classes' => false]);
        return $decoded === false && $payload !== serialize(false) ? null : $decoded;
    }

    public static function searchable(mixed $value): string
    {
        return strtolower(var_export($value, true));
    }
}
