<?php

declare(strict_types=1);

namespace Jah\JAS\Cluster;

use RuntimeException;

final class NodeIdentity
{
    public function __construct(
        public readonly string $id,
        public readonly string $endpoint,
        public readonly array $capabilities,
        public readonly string $publicKey,
        public readonly string $secretKey
    ) {
        if ($id === '' || !preg_match('/^[A-Za-z0-9._:-]{3,128}$/', $id)) throw new RuntimeException('node_id_invalid');
        if ($endpoint === '') throw new RuntimeException('node_endpoint_invalid');
        if (extension_loaded('sodium')) {
            if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) throw new RuntimeException('node_public_key_invalid');
            if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new RuntimeException('node_secret_key_invalid');
            if (!hash_equals($publicKey, sodium_crypto_sign_publickey_from_secretkey($secretKey))) throw new RuntimeException('node_keypair_mismatch');
        }
    }

    public static function loadOrCreate(string $directory, string $id, string $endpoint, array $capabilities = ['*']): self
    {
        if (!extension_loaded('sodium')) throw new RuntimeException('sodium_required_for_cluster');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('node_identity_directory_failed');
        $path = rtrim($directory, '/') . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $id) . '.identity';
        if (is_file($path)) {
            $data = unserialize((string)file_get_contents($path), ['allowed_classes'=>false]);
            if (!is_array($data) || !isset($data['public'], $data['secret'])) throw new RuntimeException('node_identity_corrupt');
            $publicKey = base64_decode((string) $data['public'], true);
            $secretKey = base64_decode((string) $data['secret'], true);
            if (!is_string($publicKey) || !is_string($secretKey)) throw new RuntimeException('node_identity_corrupt');
            return new self($id, $endpoint, $capabilities, $publicKey, $secretKey);
        }
        $pair = sodium_crypto_sign_keypair();
        $identity = new self($id, $endpoint, $capabilities, sodium_crypto_sign_publickey($pair), sodium_crypto_sign_secretkey($pair));
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $payload = serialize(['public'=>base64_encode($identity->publicKey),'secret'=>base64_encode($identity->secretKey)]);
        if (file_put_contents($tmp, $payload, LOCK_EX) !== strlen($payload) || !rename($tmp, $path)) throw new RuntimeException('node_identity_write_failed');
        @chmod($path, 0600);
        return $identity;
    }

    public function sign(string $message): string { return sodium_crypto_sign_detached($message, $this->secretKey); }
    public static function verify(string $message, string $signature, string $publicKey): bool { return sodium_crypto_sign_verify_detached($signature, $message, $publicKey); }
}
