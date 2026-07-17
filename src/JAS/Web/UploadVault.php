<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use Jah\DataCore\DataCoreDatabase;
use Jah\DataCore\PhpSerializer;
use Jah\JAS\Persistence\AuditJournal;
use Jah\JAS\Security\KeyRing;
use Jah\JAS\Type\TypeRegistry;
use RuntimeException;
use Throwable;

final class UploadVault
{
    private const COLLECTION = 'web_uploads';
    private const CHUNK_BYTES = 65_536;
    private readonly string $directory;

    public function __construct(
        private readonly DataCoreDatabase $database,
        private readonly KeyRing $keys,
        private readonly AuditJournal $audit,
        string $directory,
        string $documentRoot,
        private readonly string $pepper,
        private readonly UploadScanner $scanner,
    ) {
        if (strlen($pepper) < 32) throw new RuntimeException('upload_pepper_invalid');
        if (!is_dir($documentRoot)) throw new RuntimeException('upload_document_root_invalid');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('upload_vault_directory_failed');
        $vault = realpath($directory);
        $public = realpath($documentRoot);
        if ($vault === false || $public === false || $vault === $public || str_starts_with($vault . '/', rtrim($public, '/') . '/')) {
            throw new RuntimeException('upload_vault_public_forbidden');
        }
        @chmod($vault, 0700);
        $this->directory = $vault;
    }

    public static function defineTypes(TypeRegistry $types): void
    {
        $types->define('WebUpload', [
            'id' => 'identifier', 'owner_lookup' => 'non-empty-string',
            'owner_id' => 'identifier', 'original_name' => 'non-empty-string',
            'policy' => 'identifier', 'mime' => 'non-empty-string',
            'size' => 'positive-int', 'sha256' => 'non-empty-string',
            'chunks' => 'positive-int', 'status' => 'identifier',
            'created_at' => 'positive-int',
        ]);
    }

    public static function configureDatabase(DataCoreDatabase $database): void
    {
        $database->collection(self::COLLECTION, 'WebUpload')
            ->index(self::COLLECTION, 'uploads_by_owner', ['owner_lookup'])
            ->encryptFields(self::COLLECTION, ['owner_id', 'original_name', 'sha256']);
    }

    public function store(UploadedFile $upload, string $ownerId, UploadPolicy $policy, string $requestId): array
    {
        $this->identifier($ownerId, 'upload_owner_invalid');
        $this->requestId($requestId);
        $id = 'UPLOAD-' . bin2hex(random_bytes(16));
        $stage = $this->directory . '/.' . $id . '.stage';
        $part = $this->directory . '/.' . $id . '.part';
        $final = $this->path($id);
        $stored = false;
        $metadataStored = false;
        try {
            $inspection = $this->stageAndInspect($upload, $stage, $policy);
            $this->scanner->assertSafe($stage, $inspection['mime'], $inspection['sha256']);
            $chunks = $this->encryptFile($stage, $part, $id);
            if (!rename($part, $final) || !chmod($final, 0600)) throw new RuntimeException('upload_custody_commit_failed');
            $stored = true;
            $document = [
                'id' => $id,
                'owner_lookup' => $this->lookup($ownerId),
                'owner_id' => $ownerId,
                'original_name' => $upload->originalName,
                'policy' => $policy->name,
                'mime' => $inspection['mime'],
                'size' => $inspection['size'],
                'sha256' => $inspection['sha256'],
                'chunks' => $chunks,
                'status' => 'active',
                'created_at' => time(),
            ];
            $created = $this->database->insert(self::COLLECTION, $document);
            $metadataStored = true;
            $this->record($ownerId, 'web.upload.store', $requestId, ['id' => $id, 'mime' => $inspection['mime'], 'size' => $inspection['size'], 'sha256' => $inspection['sha256']], true);
            return $created;
        } catch (Throwable $exception) {
            if ($metadataStored) {
                try { $this->database->delete(self::COLLECTION, $id, 1); } catch (Throwable) {}
            }
            if ($stored) @unlink($final);
            $this->record($ownerId, 'web.upload.store', $requestId, ['id' => $id, 'policy' => $policy->name], false, $this->errorCode($exception));
            throw $exception;
        } finally {
            @unlink($stage);
            @unlink($part);
        }
    }

    public function read(string $id, string $principalId, string $requestId): string
    {
        $this->identifier($id, 'upload_id_invalid');
        $this->identifier($principalId, 'upload_principal_invalid');
        $this->requestId($requestId);
        try {
            $metadata = $this->database->find(self::COLLECTION, $id);
            if (!is_array($metadata) || ($metadata['status'] ?? null) !== 'active'
                || !hash_equals((string) ($metadata['owner_lookup'] ?? ''), $this->lookup($principalId))) {
                throw new RuntimeException('upload_access_denied');
            }
            $bytes = $this->decryptFile($this->path($id), $id, (int) $metadata['chunks']);
            if (strlen($bytes) !== (int) $metadata['size'] || !hash_equals((string) $metadata['sha256'], hash('sha256', $bytes))) {
                throw new RuntimeException('upload_custody_invalid');
            }
            $this->record($principalId, 'web.upload.read', $requestId, ['id' => $id, 'sha256' => $metadata['sha256']], true);
            return $bytes;
        } catch (Throwable $exception) {
            $this->record($principalId, 'web.upload.read', $requestId, ['id' => $id], false, $this->errorCode($exception));
            throw $exception;
        }
    }

    /** @return array{size:int,sha256:string,mime:string} */
    private function stageAndInspect(UploadedFile $upload, string $stage, UploadPolicy $policy): array
    {
        $source = fopen($upload->path(), 'rb');
        $target = fopen($stage, 'xb');
        if ($source === false || $target === false) {
            if (is_resource($source)) fclose($source);
            if (is_resource($target)) fclose($target);
            throw new RuntimeException('upload_staging_failed');
        }
        @chmod($stage, 0600);
        $size = 0;
        $hash = hash_init('sha256');
        try {
            while (!feof($source)) {
                $chunk = fread($source, self::CHUNK_BYTES);
                if ($chunk === false) throw new RuntimeException('upload_read_failed');
                if ($chunk === '') continue;
                $size += strlen($chunk);
                if ($size > $policy->maxBytes) throw new RuntimeException('upload_size_exceeded');
                hash_update($hash, $chunk);
                if (fwrite($target, $chunk) !== strlen($chunk)) throw new RuntimeException('upload_staging_failed');
            }
            if ($size < 1 || !fflush($target)) throw new RuntimeException('upload_empty_forbidden');
            if (function_exists('fsync')) @fsync($target);
        } finally {
            fclose($source);
            fclose($target);
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($stage);
        if (!is_string($mime) || !$policy->accepts($mime)) throw new RuntimeException('upload_mime_not_allowed');
        $this->assertKnownSignature($stage, $mime);
        return ['size' => $size, 'sha256' => hash_final($hash), 'mime' => $mime];
    }

    private function encryptFile(string $sourcePath, string $targetPath, string $id): int
    {
        $source = fopen($sourcePath, 'rb');
        $target = fopen($targetPath, 'xb');
        if ($source === false || $target === false) throw new RuntimeException('upload_custody_open_failed');
        @chmod($targetPath, 0600);
        $index = 0;
        try {
            while (!feof($source)) {
                $chunk = fread($source, self::CHUNK_BYTES);
                if ($chunk === false) throw new RuntimeException('upload_read_failed');
                if ($chunk === '') continue;
                $sealed = $this->keys->encrypt('jas.web.upload.' . $id . '.' . $index, $chunk);
                $line = PhpSerializer::encode(['index' => $index, 'key_id' => $sealed['key_id'], 'ciphertext' => $sealed['ciphertext']]) . "\n";
                if (fwrite($target, $line) !== strlen($line)) throw new RuntimeException('upload_custody_write_failed');
                $index++;
            }
            if ($index < 1 || !fflush($target)) throw new RuntimeException('upload_custody_write_failed');
            if (function_exists('fsync')) @fsync($target);
            return $index;
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    private function decryptFile(string $path, string $id, int $expectedChunks): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false || $expectedChunks < 1) throw new RuntimeException('upload_custody_invalid');
        $bytes = '';
        $index = 0;
        try {
            while (($line = fgets($handle, 262_144)) !== false) {
                if (!str_ends_with($line, "\n")) throw new RuntimeException('upload_custody_invalid');
                $entry = PhpSerializer::decode(rtrim($line, "\r\n"));
                if (!is_array($entry) || ($entry['index'] ?? null) !== $index
                    || !is_string($entry['key_id'] ?? null) || !is_string($entry['ciphertext'] ?? null)) {
                    throw new RuntimeException('upload_custody_invalid');
                }
                $bytes .= $this->keys->decrypt('jas.web.upload.' . $id . '.' . $index, $entry['key_id'], $entry['ciphertext']);
                $index++;
            }
        } catch (Throwable) {
            throw new RuntimeException('upload_custody_invalid');
        } finally {
            fclose($handle);
        }
        if ($index !== $expectedChunks) throw new RuntimeException('upload_custody_invalid');
        return $bytes;
    }

    private function assertKnownSignature(string $path, string $mime): void
    {
        $head = file_get_contents($path, false, null, 0, 16);
        if (!is_string($head)) throw new RuntimeException('upload_read_failed');
        $valid = match ($mime) {
            'application/pdf' => str_starts_with($head, '%PDF-'),
            'image/png' => str_starts_with($head, "\x89PNG\r\n\x1a\n"),
            'image/jpeg' => str_starts_with($head, "\xff\xd8\xff"),
            'image/gif' => str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a'),
            'text/plain' => !str_contains($head, "\0"),
            default => true,
        };
        if (!$valid) throw new RuntimeException('upload_signature_invalid');
    }

    private function path(string $id): string { return $this->directory . '/' . $id . '.jahu'; }
    private function lookup(string $principal): string { return hash_hmac('sha256', $principal, $this->pepper); }

    private function identifier(string $value, string $error): void
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{3,128}$/', $value)) throw new RuntimeException($error);
    }

    private function requestId(string $value): void
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/', $value)) throw new RuntimeException('upload_request_id_invalid');
    }

    private function record(string $principal, string $action, string $requestId, array $context, bool $success, ?string $error = null): void
    {
        $this->audit->record($principal, $action, $requestId, $success, hash('sha256', PhpSerializer::encode($context)), $error);
    }

    private function errorCode(Throwable $exception): string
    {
        $code = $exception->getMessage();
        return preg_match('/^[a-z0-9_.:-]{3,128}$/i', $code) === 1 ? $code : 'upload_operation_failed';
    }
}
