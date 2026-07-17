<?php

declare(strict_types=1);

if (!function_exists('jah_env')) {
    function jah_env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        $normalized = strtolower(trim((string) $value));
        return match ($normalized) {
            'true', '1', 'yes', 'on' => true,
            'false', '0', 'no', 'off' => false,
            'null' => null,
            default => $value,
        };
    }
}

if (!function_exists('jah_int_env')) {
    function jah_int_env(string $key, int $default): int
    {
        $value = jah_env($key, $default);
        return filter_var($value, FILTER_VALIDATE_INT) === false ? $default : (int) $value;
    }
}
