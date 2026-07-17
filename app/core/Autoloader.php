<?php

declare(strict_types=1);

/**
 * JAH Autoloader — PSR-4 manual
 * Registra los namespaces del Motor PHP JAH.
 */
class Autoloader
{
    /** @var array<string, string> namespace_prefix => base_directory */
    private static array $prefixes = [];

    /**
     * Registra el autoloader en el stack de SPL.
     */
    public static function register(): void
    {
        spl_autoload_register([self::class, 'load']);
    }

    /**
     * Añade un namespace con su directorio base.
     */
    public static function addNamespace(string $prefix, string $baseDir): void
    {
        $prefix  = rtrim($prefix, '\\') . '\\';
        $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        self::$prefixes[$prefix] = $baseDir;
    }

    /**
     * Intenta cargar la clase según el namespace registrado.
     */
    public static function load(string $class): bool
    {
        foreach (self::$prefixes as $prefix => $baseDir) {
            if (str_starts_with($class, $prefix)) {
                $relative = substr($class, strlen($prefix));
                $file     = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

                if (is_file($file)) {
                    require $file;
                    return true;
                }
            }
        }
        return false;
    }
}
