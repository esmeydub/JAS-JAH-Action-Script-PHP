<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/support.php';

use Jah\DataCore\DataCoreDatabase;
use Jah\DataCore\DataCoreTurbo;
use Jah\JAS\Persistence\AuditJournal;
use Jah\JAS\Security\KeyRing;
use Jah\JAS\Type\TypeRegistry;
use Jah\JAS\Web\UploadedFile;
use Jah\JAS\Web\UploadPolicy;
use Jah\JAS\Web\UploadAccessPolicy;
use Jah\JAS\Web\UploadScanner;
use Jah\JAS\Web\UploadVault;

$throws = static function (callable $operation, string $expected): void {
    try { $operation(); } catch (Throwable $exception) { if ($exception->getMessage() === $expected) return; throw $exception; }
    throw new RuntimeException("Expected {$expected}");
};

$base = sys_get_temp_dir() . '/jas_upload_vault_' . bin2hex(random_bytes(5));
mkdir($base . '/public', 0700, true);
$types = new TypeRegistry();
UploadVault::defineTypes($types);
$storage = new DataCoreTurbo($base . '/datacore', 1);
$keys = new KeyRing(['upload-2026' => random_bytes(32)], 'upload-2026');
$database = new DataCoreDatabase($storage, $types, $base . '/runtime', $keys);
UploadVault::configureDatabase($database);
$audit = new AuditJournal($base . '/audit');
$uploadPepper = random_bytes(32);
$scanner = new class implements UploadScanner {
    public int $calls = 0;
    public function assertSafe(string $path, string $mime, string $sha256): void
    {
        $this->calls++;
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) throw new RuntimeException('upload_scanner_unavailable');
        if (str_contains($bytes, 'EICAR-TEST')) throw new RuntimeException('upload_malware_detected');
    }
};
$vault = new UploadVault(
    $database,
    $keys,
    $audit,
    $base . '/private-uploads',
    $base . '/public',
    $uploadPepper,
    $scanner,
);
$documents = new UploadPolicy('citizen-documents', ['text/plain'], 1_024);
$content = "clean government document\n";
$created = $vault->store(UploadedFile::fromBytes('solicitud.txt', $content), 'USER-OWNER', $documents, 'UPLOAD-REQ-0001');
$id = (string) ($created['id'] ?? '');
if (!str_starts_with($id, 'UPLOAD-') || ($created['mime'] ?? null) !== 'text/plain') throw new RuntimeException('upload_metadata_failed');
if (($created['size'] ?? null) !== strlen($content) || ($created['sha256'] ?? null) !== hash('sha256', $content)) throw new RuntimeException('upload_hash_or_size_failed');
if ($scanner->calls !== 1) throw new RuntimeException('upload_scanner_not_called');

$custodyPath = $base . '/private-uploads/' . $id . '.jahu';
$ciphertext = file_get_contents($custodyPath);
if (!is_string($ciphertext) || str_contains($ciphertext, $content) || (fileperms($custodyPath) & 0777) !== 0600) {
    throw new RuntimeException('upload_not_encrypted_at_rest');
}
$raw = $storage->find('web_uploads', $id);
if (!is_array($raw['owner_id'] ?? null) || !is_array($raw['original_name'] ?? null) || !is_array($raw['sha256'] ?? null)) {
    throw new RuntimeException('upload_sensitive_metadata_not_encrypted');
}
if ($vault->read($id, 'USER-OWNER', 'DOWNLOAD-REQ-0001') !== $content) throw new RuntimeException('upload_custody_roundtrip_failed');
$throws(fn() => $vault->read($id, 'USER-OTHER', 'DOWNLOAD-REQ-0002'), 'upload_access_denied');

$download = $vault->downloadResponse($id, 'USER-OWNER', 'DOWNLOAD-REQ-STREAM-1');
if (!$download->isStreamed() || $download->contentType !== 'text/plain' || ($download->headers['Content-Length'] ?? null) !== (string) strlen($content)) {
    throw new RuntimeException('authorized_download_response_failed');
}
if (!str_contains((string) ($download->headers['Content-Disposition'] ?? ''), 'attachment; filename="solicitud.txt"')) throw new RuntimeException('download_disposition_failed');
$downloaded = '';
$download->emit(static function (string $chunk) use (&$downloaded): void { $downloaded .= $chunk; });
if ($downloaded !== $content) throw new RuntimeException('streamed_download_roundtrip_failed');
$throws(fn() => $download->emit(static function (): void {}), 'response_stream_already_consumed');
$throws(fn() => $vault->downloadResponse($id, 'USER-OTHER', 'DOWNLOAD-REQ-STREAM-2'), 'upload_access_denied');
$delegatedVault = new UploadVault(
    $database,
    $keys,
    $audit,
    $base . '/private-uploads',
    $base . '/public',
    $uploadPepper,
    $scanner,
    new class implements UploadAccessPolicy {
        public function canDownload(string $principalId, array $metadata): bool
        {
            return $principalId === 'USER-AUDITOR' && ($metadata['policy'] ?? null) === 'citizen-documents';
        }
    },
);
$delegatedDownload = $delegatedVault->downloadResponse($id, 'USER-AUDITOR', 'DOWNLOAD-REQ-DELEGATED-1');
$delegatedBytes = '';
$delegatedDownload->emit(static function (string $chunk) use (&$delegatedBytes): void { $delegatedBytes .= $chunk; });
if ($delegatedBytes !== $content) throw new RuntimeException('delegated_download_failed');

$largeContent = str_repeat("bounded streaming line\n", 8_000);
$large = $vault->store(
    UploadedFile::fromBytes('résumé "final".txt', $largeContent),
    'USER-OWNER',
    new UploadPolicy('large-documents', ['text/plain'], 1_048_576),
    'UPLOAD-REQ-LARGE-1',
);
$largeResponse = $vault->downloadResponse((string) $large['id'], 'USER-OWNER', 'DOWNLOAD-REQ-LARGE-1');
$largeDownloaded = '';
$chunkSizes = [];
$largeResponse->emit(static function (string $chunk) use (&$largeDownloaded, &$chunkSizes): void {
    $largeDownloaded .= $chunk;
    $chunkSizes[] = strlen($chunk);
});
if ($largeDownloaded !== $largeContent || count($chunkSizes) < 2 || max($chunkSizes) > 65_536) throw new RuntimeException('bounded_streaming_failed');
$largeDisposition = (string) ($largeResponse->headers['Content-Disposition'] ?? '');
if (!str_contains($largeDisposition, "filename*=UTF-8''") || str_contains($largeDisposition, 'filename="résumé')) throw new RuntimeException('unicode_download_filename_failed');

$throws(
    fn() => $vault->store(UploadedFile::fromBytes('too-large.txt', str_repeat('A', 20)), 'USER-OWNER', new UploadPolicy('tiny-documents', ['text/plain'], 10), 'UPLOAD-REQ-0002'),
    'upload_size_exceeded',
);
$throws(
    fn() => $vault->store(UploadedFile::fromBytes('photo.png', 'this is not a png'), 'USER-OWNER', new UploadPolicy('png-images', ['image/png'], 1_024), 'UPLOAD-REQ-0003'),
    'upload_mime_not_allowed',
);
$throws(
    fn() => $vault->store(UploadedFile::fromBytes('scan.txt', 'EICAR-TEST'), 'USER-OWNER', $documents, 'UPLOAD-REQ-0004'),
    'upload_malware_detected',
);
$throws(fn() => UploadedFile::fromBytes('../escape.txt', 'bad'), 'upload_original_name_invalid');
$throws(fn() => UploadedFile::fromPhpUpload(['name' => 'fake.txt', 'tmp_name' => __FILE__, 'error' => UPLOAD_ERR_OK]), 'upload_transport_invalid');
$throws(fn() => new UploadPolicy('active-content', ['text/html'], 1_024), 'upload_policy_active_content_forbidden');
$throws(
    fn() => new UploadVault($database, $keys, $audit, $base . '/public/uploads', $base . '/public', random_bytes(32), $scanner),
    'upload_vault_public_forbidden',
);

file_put_contents($custodyPath, "corrupt", FILE_APPEND | LOCK_EX);
$throws(fn() => $vault->read($id, 'USER-OWNER', 'DOWNLOAD-REQ-0003'), 'upload_custody_invalid');
$throws(fn() => $vault->downloadResponse($id, 'USER-OWNER', 'DOWNLOAD-REQ-CORRUPT-1'), 'upload_custody_invalid');
if (!$audit->verify()) throw new RuntimeException('upload_audit_integrity_failed');

jas_test_remove_tree($base);
echo "JAS UPLOAD CUSTODY: PASS\n";
