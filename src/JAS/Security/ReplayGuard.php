<?php

declare(strict_types=1);

namespace Jah\JAS\Security;

use RuntimeException;

final class ReplayGuard
{
    public function __construct(private readonly string $directory, private readonly int $ttlSeconds = 300)
    {
        if ($ttlSeconds < 1 || $ttlSeconds > 86_400) {
            throw new RuntimeException('replay_ttl_invalid');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("No se pudo crear ReplayGuard: {$directory}");
        }
    }

    public function assertFresh(string $requestId, int $timestamp): void
    {
        if ($requestId === '' || strlen($requestId) > 255) {
            throw new RuntimeException('replay_request_id_invalid');
        }
        $now = time();
        if (abs($now - $timestamp) > $this->ttlSeconds) {
            throw new RuntimeException('Paquete JAS vencido o con reloj inválido');
        }
        $path = $this->directory . '/' . hash('sha256', $requestId) . '.seen';
        $handle = fopen($path, 'c+b');
        if ($handle === false) throw new RuntimeException('No se pudo abrir registro anti-replay');
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('No se pudo bloquear registro anti-replay');
            $content = stream_get_contents($handle);
            if (is_string($content) && trim($content) !== '') {
                throw new RuntimeException('Replay SALK detectado');
            }
            ftruncate($handle, 0);
            rewind($handle);
            $encoded = (string) $timestamp;
            if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
                throw new RuntimeException('No se pudo persistir registro anti-replay');
            }
            if (function_exists('fsync')) @fsync($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        $this->garbageCollect($now);
    }

    private function garbageCollect(int $now): void
    {
        if (random_int(1, 100) !== 1) return;
        foreach (glob($this->directory . '/*.seen') ?: [] as $file) {
            $mtime = filemtime($file);
            if ($mtime !== false && ($now - $mtime) > ($this->ttlSeconds * 2)) @unlink($file);
        }
    }
}
