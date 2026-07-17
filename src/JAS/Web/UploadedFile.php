<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use RuntimeException;

final class UploadedFile
{
    private function __construct(
        public readonly string $originalName,
        private readonly string $temporaryPath,
        private readonly bool $ownedTemporaryFile,
    ) {
        if ($originalName === '' || strlen($originalName) > 255 || preg_match('~[\x00-\x1F\x7F\\/]~', $originalName)) {
            throw new RuntimeException('upload_original_name_invalid');
        }
        if (preg_match('//u', $originalName) !== 1 || !is_file($temporaryPath) || is_link($temporaryPath)) {
            throw new RuntimeException('upload_temporary_file_invalid');
        }
    }

    public static function fromPhpUpload(array $file): self
    {
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK) throw new RuntimeException('upload_transport_failed');
        $path = $file['tmp_name'] ?? null;
        $name = $file['name'] ?? null;
        if (!is_string($path) || !is_string($name) || !is_uploaded_file($path)) {
            throw new RuntimeException('upload_transport_invalid');
        }
        return new self($name, $path, false);
    }

    /** Para pruebas, importadores y procesos internos que ya poseen los bytes. */
    public static function fromBytes(string $originalName, string $bytes): self
    {
        $path = tempnam(sys_get_temp_dir(), 'jas_upload_');
        if ($path === false) throw new RuntimeException('upload_temporary_file_failed');
        try {
            if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes) || !chmod($path, 0600)) {
                throw new RuntimeException('upload_temporary_file_failed');
            }
            return new self($originalName, $path, true);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
    }

    public function path(): string { return $this->temporaryPath; }

    public function __destruct()
    {
        if ($this->ownedTemporaryFile) @unlink($this->temporaryPath);
    }
}
