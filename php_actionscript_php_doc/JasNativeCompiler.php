<?php

declare(strict_types=1);

namespace Jah;

use Throwable;

/**
 * Validates PHP with PHP's own parser and writes an executable PHP artifact.
 */
final class JasNativeCompiler
{
    public static function compile(string $source, string $output): bool
    {
        if (!self::validate($source) || str_contains($source, "\0")) {
            return false;
        }

        $directory = dirname($output);
        if (!is_dir($directory) || !is_writable($directory)) {
            return false;
        }

        $temporary = tempnam($directory, '.jah-compile-');
        if ($temporary === false) {
            return false;
        }

        try {
            $written = file_put_contents($temporary, $source, LOCK_EX);
            if ($written !== strlen($source)) {
                return false;
            }

            @chmod($temporary, 0750);
            return rename($temporary, $output);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public static function validate(string $code): bool
    {
        if (trim($code) === '' || !str_contains($code, '<?php')) {
            return false;
        }

        try {
            token_get_all($code, TOKEN_PARSE);
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
