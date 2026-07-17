<?php

declare(strict_types=1);

namespace Jah\JAS\Snapshot;

use Jah\DataCore\PhpSerializer;
use RuntimeException;
use Throwable;

final class DistributedSnapshotManager
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('snapshot_directory_failed');
        }
    }

    public function create(string $snapshotId, array $sources, array $metadata = []): array
    {
        $target = $this->snapshotDirectory($snapshotId);
        if (is_dir($target)) {
            throw new RuntimeException('snapshot_exists');
        }
        if (!mkdir($target, 0700, true) && !is_dir($target)) {
            throw new RuntimeException('snapshot_directory_failed');
        }

        $files = [];
        foreach ($sources as $name => $path) {
            if (!is_file($path)) {
                continue;
            }
            $destination = $target . '/' . $this->safeName((string) $name);
            if (!copy($path, $destination)) {
                throw new RuntimeException('snapshot_copy_failed');
            }
            @chmod($destination, 0600);
            $files[] = [
                'name' => basename($destination),
                'bytes' => filesize($destination),
                'sha256' => hash_file('sha256', $destination),
            ];
        }

        $manifest = [
            'id' => $snapshotId,
            'created_at' => microtime(true),
            'metadata' => $metadata,
            'files' => $files,
        ];
        $contents = PhpSerializer::encode($manifest) . "\n";
        $manifestPath = $target . '/manifest.jahl';
        if (file_put_contents($manifestPath, $contents, LOCK_EX) !== strlen($contents)) {
            throw new RuntimeException('snapshot_manifest_write_failed');
        }
        @chmod($manifestPath, 0600);
        return $manifest;
    }

    public function verify(string $snapshotId): bool
    {
        $directory = $this->snapshotDirectory($snapshotId);
        foreach ($this->manifest($snapshotId)['files'] ?? [] as $file) {
            $path = $directory . '/' . $file['name'];
            $hash = is_file($path) ? hash_file('sha256', $path) : false;
            if (
                $hash === false
                || filesize($path) !== (int) $file['bytes']
                || !hash_equals((string) $file['sha256'], $hash)
            ) {
                return false;
            }
        }
        return true;
    }

    public function manifest(string $snapshotId): array
    {
        $path = $this->snapshotDirectory($snapshotId) . '/manifest.jahl';
        if (!is_file($path)) {
            throw new RuntimeException('snapshot_not_found');
        }
        $manifest = PhpSerializer::decode(trim((string) file_get_contents($path)));
        if (!is_array($manifest)) {
            throw new RuntimeException('snapshot_manifest_corrupt');
        }
        return $manifest;
    }

    public function restore(string $snapshotId, array $destinations): void
    {
        if (!$this->verify($snapshotId)) {
            throw new RuntimeException('snapshot_verification_failed');
        }

        $sourceDirectory = $this->snapshotDirectory($snapshotId);
        foreach ($destinations as $name => $destination) {
            $source = $sourceDirectory . '/' . $this->safeName((string) $name);
            if (!is_file($source)) {
                continue;
            }
            $parent = dirname($destination);
            if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                throw new RuntimeException('snapshot_restore_directory_failed');
            }
            $temporary = $destination . '.tmp.' . bin2hex(random_bytes(4));
            if (!copy($source, $temporary) || !rename($temporary, $destination)) {
                @unlink($temporary);
                throw new RuntimeException('snapshot_restore_failed');
            }
            @chmod($destination, 0600);
        }
    }

    public function list(): array
    {
        $snapshots = [];
        foreach (glob(rtrim($this->directory, '/') . '/*/manifest.jahl') ?: [] as $path) {
            try {
                $manifest = PhpSerializer::decode(trim((string) file_get_contents($path)));
                if (is_array($manifest)) {
                    $snapshots[] = $manifest;
                }
            } catch (Throwable) {
                // Un manifiesto corrupto no oculta los snapshots válidos.
            }
        }
        usort(
            $snapshots,
            fn(array $left, array $right): int => ($right['created_at'] ?? 0) <=> ($left['created_at'] ?? 0),
        );
        return $snapshots;
    }

    private function snapshotDirectory(string $snapshotId): string
    {
        return rtrim($this->directory, '/') . '/' . $this->safeName($snapshotId);
    }

    private function safeName(string $name): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    }
}
