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
    random_bytes(32),
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
if (!$audit->verify()) throw new RuntimeException('upload_audit_integrity_failed');

jas_test_remove_tree($base);
echo "JAS UPLOAD CUSTODY: PASS\n";
