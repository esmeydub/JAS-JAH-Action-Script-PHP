<?php

declare(strict_types=1);

namespace Jah\JAS\Diagnostics;

use Jah\DataCore\PhpSerializer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class CoreIntegrityGuard
{
    private const PATHS = ['src/JAS', 'src/DataCore', 'bin/jas', 'app/bootstrap.php'];

    public function seal(string $root): array
    {
        $root = $this->root($root);
        $directory = $root . '/.jas';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('core_seal_directory_failed');
        if (is_link($directory)) throw new RuntimeException('core_seal_symlink_forbidden');
        $keyFile = $directory . '/core-seal.key';
        $target = $directory . '/core-seal.jasb';
        if (is_link($keyFile) || is_link($target)) throw new RuntimeException('core_seal_symlink_forbidden');
        if (!is_file($keyFile)) {
            if (file_put_contents($keyFile, random_bytes(32), LOCK_EX) !== 32) throw new RuntimeException('core_seal_key_write_failed');
            chmod($keyFile, 0600);
        }
        $key = file_get_contents($keyFile);
        if (!is_string($key) || strlen($key) !== 32) throw new RuntimeException('core_seal_key_invalid');
        $manifest = ['version' => 1, 'created_at' => gmdate('c'), 'files' => $this->inventory($root)];
        $payload = PhpSerializer::encode($manifest);
        $envelope = PhpSerializer::encode(['manifest' => $manifest, 'signature' => hash_hmac('sha512', $payload, $key)]);
        if (file_put_contents($target, $envelope, LOCK_EX) !== strlen($envelope)) throw new RuntimeException('core_seal_write_failed');
        chmod($target, 0600);
        return ['sealed' => true, 'files' => count($manifest['files']), 'manifest' => '.jas/core-seal.jasb'];
    }

    public function verify(string $root): array
    {
        $root = $this->root($root);
        if (is_link($root . '/.jas') || is_link($root . '/.jas/core-seal.key') || is_link($root . '/.jas/core-seal.jasb')) {
            throw new RuntimeException('core_seal_symlink_forbidden');
        }
        $key = @file_get_contents($root . '/.jas/core-seal.key');
        $encoded = @file_get_contents($root . '/.jas/core-seal.jasb');
        if (!is_string($key) || strlen($key) !== 32 || !is_string($encoded)) throw new RuntimeException('core_seal_missing');
        $envelope = PhpSerializer::decode($encoded);
        if (!is_array($envelope) || !is_array($envelope['manifest'] ?? null) || !is_string($envelope['signature'] ?? null)) throw new RuntimeException('core_seal_invalid');
        $expected = hash_hmac('sha512', PhpSerializer::encode($envelope['manifest']), $key);
        if (!hash_equals($expected, $envelope['signature'])) throw new RuntimeException('core_seal_signature_invalid');
        $sealed = $envelope['manifest']['files'] ?? [];
        if (!is_array($sealed)) throw new RuntimeException('core_seal_invalid');
        $current = $this->inventory($root);
        $violations = [];
        foreach ($sealed as $file => $hash) {
            if (!isset($current[$file])) $violations[$file] = 'missing';
            elseif (!hash_equals((string) $hash, $current[$file])) $violations[$file] = 'modified';
        }
        foreach (array_diff_key($current, $sealed) as $file => $_) $violations[$file] = 'unexpected';
        ksort($violations);
        return ['valid' => $violations === [], 'files' => count($current), 'violations' => $violations];
    }

    /** @return array<string,string> */
    private function inventory(string $root): array
    {
        $files = [];
        foreach (self::PATHS as $relative) {
            $path = $root . '/' . $relative;
            if (is_file($path) && !is_link($path)) { $files[$relative] = hash_file('sha256', $path); continue; }
            if (!is_dir($path) || is_link($path)) continue;
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                if (!$entry->isFile() || $entry->isLink()) continue;
                $file = str_replace('\\', '/', ltrim(substr($entry->getPathname(), strlen($root)), '/'));
                $files[$file] = hash_file('sha256', $entry->getPathname());
            }
        }
        ksort($files);
        return $files;
    }

    private function root(string $root): string
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) throw new RuntimeException('core_root_invalid');
        return $resolved;
    }
}
