<?php

declare(strict_types=1);

namespace Jah\JAS\Security;

use RuntimeException;

final class KeyRing
{
    /** @param array<string,string> $keys */
    public function __construct(private readonly array $keys, private readonly string $activeKeyId)
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $activeKeyId) || !isset($keys[$activeKeyId])) throw new RuntimeException('keyring_active_key_invalid');
        foreach ($keys as $id => $key) {
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $id) || !is_string($key) || strlen($key) < 32) throw new RuntimeException('keyring_key_invalid');
        }
    }

    public function activeKeyId(): string { return $this->activeKeyId; }

    /** @return array{key_id:string,ciphertext:string} */
    public function encrypt(string $purpose, string $plaintext): array
    {
        $key = $this->derived($this->activeKeyId, $purpose, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return ['key_id' => $this->activeKeyId, 'ciphertext' => base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key))];
    }

    public function decrypt(string $purpose, string $keyId, string $ciphertext): string
    {
        $binary = base64_decode($ciphertext, true);
        if (!is_string($binary) || strlen($binary) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new RuntimeException('keyring_ciphertext_invalid');
        $nonce = substr($binary, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($binary, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->derived($keyId, $purpose, SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        if ($plain === false) throw new RuntimeException('keyring_decryption_failed');
        return $plain;
    }

    public function sign(string $purpose, string $data, ?string $keyId = null): array
    {
        $id = $keyId ?? $this->activeKeyId;
        return ['key_id' => $id, 'signature' => hash_hmac('sha512', $data, $this->derived($id, $purpose, 64))];
    }

    public function verify(string $purpose, string $data, string $keyId, string $signature): bool
    {
        return hash_equals($this->sign($purpose, $data, $keyId)['signature'], $signature);
    }

    private function derived(string $keyId, string $purpose, int $length): string
    {
        $key = $this->keys[$keyId] ?? throw new RuntimeException('keyring_key_not_found');
        return sodium_crypto_generichash($purpose, $key, $length);
    }
}
