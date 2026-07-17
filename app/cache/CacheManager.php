<?php

declare(strict_types=1);

namespace Jah\Cache;

/**
 * CacheManager — Administrador de almacenamiento en caché persistente en disco (archivos locales).
 */
class CacheManager
{
    private string $storePath;

    public function __construct(string $storePath)
    {
        $this->storePath = rtrim($storePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->ensureStoreDirectory();
    }

    /**
     * Recupera un valor del caché de archivos. Retorna null si no existe o ha expirado.
     */
    public function get(string $key): mixed
    {
        $file = $this->getFilePath($key);

        if (!is_file($file)) {
            return null;
        }

        $content = file_get_contents($file);
        if ($content === false || trim($content) === '') {
            $this->delete($key);
            return null;
        }

        $data = unserialize($content, ['allowed_classes' => false]);

        if (!is_array($data) || !isset($data['expire_at']) || !isset($data['value'])) {
            $this->delete($key);
            return null;
        }

        if (time() > $data['expire_at']) {
            $this->delete($key);
            return null;
        }

        return $data['value'];
    }

    /**
     * Almacena un valor en el caché por un tiempo determinado (TTL).
     */
    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        $file = $this->getFilePath($key);
        $data = [
            'value'     => $value,
            'expire_at' => time() + $ttl,
            'created'   => time(),
        ];

        $payload = serialize($data);
        return file_put_contents($file, $payload, LOCK_EX) !== false;
    }

    /**
     * Elimina una llave del caché físico.
     */
    public function delete(string $key): bool
    {
        $file = $this->getFilePath($key);
        if (is_file($file)) {
            return @unlink($file);
        }
        return false;
    }

    /**
     * Limpia completamente todo el caché en el directorio.
     */
    public function clear(): int
    {
        $count = 0;
        $files = glob($this->storePath . '*.php');
        if ($files === false) return 0;

        foreach ($files as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }
        return $count;
    }

    private function getFilePath(string $key): string
    {
        $safeKey = hash('sha256', $key);
        return $this->storePath . $safeKey . '.php';
    }

    private function ensureStoreDirectory(): void
    {
        if (!is_dir($this->storePath)) {
            mkdir($this->storePath, 0775, true);
        }
    }
}
