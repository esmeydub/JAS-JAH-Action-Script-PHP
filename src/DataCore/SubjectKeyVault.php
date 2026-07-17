<?php

declare(strict_types=1);

namespace Jah\DataCore;

use Jah\JAS\Security\KeyRing;
use RuntimeException;

final class SubjectKeyVault
{
    private string $keysDirectory;
    private string $destroyedJournal;

    public function __construct(
        private readonly string $directory,
        private readonly KeyRing $master,
        private readonly ?DataCoreContinuityLock $continuity = null,
    )
    {
        $this->keysDirectory = rtrim($directory, '/') . '/keys';
        $this->destroyedJournal = rtrim($directory, '/') . '/destroyed.jahl';
        if (!is_dir($this->keysDirectory)
            && !mkdir($this->keysDirectory, 0700, true)
            && !is_dir($this->keysDirectory)) {
            throw new RuntimeException('datacore_subject_vault_directory_failed');
        }
    }

    public function encrypt(string $subjectId, string $purpose, string $plaintext): array
    {
        if ($this->continuity !== null) {
            return $this->continuity->shared(
                fn(): array => $this->encryptUnlocked($subjectId, $purpose, $plaintext),
            );
        }
        return $this->encryptUnlocked($subjectId, $purpose, $plaintext);
    }

    private function encryptUnlocked(string $subjectId, string $purpose, string $plaintext): array
    {
        $key = $this->derivedSubjectKey($this->key($subjectId, true), $purpose);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        return [
            'subject_key_id' => $this->subjectHash($subjectId),
            'ciphertext' => base64_encode($nonce . $ciphertext),
        ];
    }

    public function decrypt(string $subjectId, string $purpose, string $ciphertext): string
    {
        $binary = base64_decode($ciphertext, true);
        if (!is_string($binary) || strlen($binary) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('datacore_subject_ciphertext_invalid');
        }
        $nonce = substr($binary, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(
            substr($binary, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $nonce,
            $this->derivedSubjectKey($this->key($subjectId, false), $purpose),
        );
        if ($plain === false) throw new RuntimeException('datacore_subject_decryption_failed');
        return $plain;
    }

    public function destroy(string $subjectId, string $principal): void
    {
        if ($this->continuity !== null) {
            $this->continuity->shared(fn() => $this->destroyUnlocked($subjectId, $principal));
            return;
        }
        $this->destroyUnlocked($subjectId, $principal);
    }

    private function destroyUnlocked(string $subjectId, string $principal): void
    {
        if ($principal === '') throw new RuntimeException('datacore_subject_destroy_principal_required');
        $hash = $this->subjectHash($subjectId);
        $path = $this->keyPath($hash);
        if (!is_file($path)) throw new RuntimeException('datacore_subject_key_not_found');
        if (!unlink($path)) throw new RuntimeException('datacore_subject_key_destroy_failed');
        $entry = [
            'subject_hash' => $hash,
            'principal' => $principal,
            'destroyed_at' => microtime(true),
        ];
        $signed = $this->master->sign('datacore-subject-destruction', PhpSerializer::encode($entry));
        $entry['signature'] = $signed['signature'];
        $entry['signature_key_id'] = $signed['key_id'];
        $line = PhpSerializer::encode($entry) . "\n";
        if (file_put_contents($this->destroyedJournal, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new RuntimeException('datacore_subject_destroy_audit_failed');
        }
        @chmod($this->destroyedJournal, 0600);
    }

    public function isDestroyed(string $subjectId): bool
    {
        $hash = $this->subjectHash($subjectId);
        foreach ($this->destructionEntries() as $entry) {
            if (($entry['subject_hash'] ?? null) === $hash) return true;
        }
        return false;
    }

    public function verifyDestructionLog(): bool
    {
        try {
            $this->destructionEntries();
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function destructionEntries(): array
    {
        if (!is_file($this->destroyedJournal)) return [];
        $entries = [];
        foreach (file($this->destroyedJournal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = PhpSerializer::decode($line);
            if (!is_array($entry)) throw new RuntimeException('datacore_subject_destroy_log_corrupt');
            $signature = $entry['signature'] ?? null;
            $keyId = $entry['signature_key_id'] ?? null;
            unset($entry['signature'], $entry['signature_key_id']);
            if (!is_string($signature) || !is_string($keyId)
                || !$this->master->verify(
                    'datacore-subject-destruction',
                    PhpSerializer::encode($entry),
                    $keyId,
                    $signature,
                )) {
                throw new RuntimeException('datacore_subject_destroy_log_signature_invalid');
            }
            $entries[] = $entry;
        }
        return $entries;
    }

    private function key(string $subjectId, bool $create): string
    {
        if ($subjectId === '') throw new RuntimeException('datacore_subject_id_required');
        if ($this->isDestroyed($subjectId)) throw new RuntimeException('datacore_subject_key_destroyed');
        $hash = $this->subjectHash($subjectId);
        $path = $this->keyPath($hash);
        if (!is_file($path) && $create) {
            $envelope = $this->master->encrypt('datacore-subject-key:' . $hash, random_bytes(32));
            $temporary = $path . '.tmp.' . bin2hex(random_bytes(4));
            $encoded = PhpSerializer::encode($envelope);
            if (file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded)
                || !rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException('datacore_subject_key_create_failed');
            }
            @chmod($path, 0600);
        }
        if (!is_file($path)) throw new RuntimeException('datacore_subject_key_not_found');
        $envelope = PhpSerializer::decode((string) file_get_contents($path));
        if (!is_array($envelope)) throw new RuntimeException('datacore_subject_key_corrupt');
        $key = $this->master->decrypt(
            'datacore-subject-key:' . $hash,
            (string) ($envelope['key_id'] ?? ''),
            (string) ($envelope['ciphertext'] ?? ''),
        );
        return $this->derivedSubjectKey($key, 'base');
    }

    private function derivedSubjectKey(string $key, string $purpose): string
    {
        return sodium_crypto_generichash($purpose, $key, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    private function subjectHash(string $subjectId): string
    {
        return hash('sha256', $subjectId);
    }

    private function keyPath(string $hash): string
    {
        return $this->keysDirectory . '/' . $hash . '.key';
    }
}
