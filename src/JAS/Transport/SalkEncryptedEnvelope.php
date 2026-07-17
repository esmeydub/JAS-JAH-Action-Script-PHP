<?php

declare(strict_types=1);

namespace Jah\JAS\Transport;

use Jah\JAS\Cluster\NodeIdentity;
use RuntimeException;
use SodiumException;

final class SalkEncryptedEnvelope
{
    private const MAGIC = "JASE\x01";
    private const MAX_SENDER_ID_BYTES = 128;
    private const MAX_PAYLOAD_BYTES = 16_777_216;

    public static function seal(string $payload, NodeIdentity $sender, string $recipientPublicKey): string
    {
        self::requireSodium();
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new RuntimeException('envelope_payload_too_large');
        }
        self::assertPublicKey($recipientPublicKey, 'recipient_public_key_invalid');

        try {
            $nonce = random_bytes(SODIUM_CRYPTO_BOX_NONCEBYTES);
            $senderSecret = sodium_crypto_sign_ed25519_sk_to_curve25519($sender->secretKey);
            $recipientPublic = sodium_crypto_sign_ed25519_pk_to_curve25519($recipientPublicKey);
            $keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($senderSecret, $recipientPublic);
            $cipher = sodium_crypto_box($payload, $nonce, $keypair);
        } catch (SodiumException $e) {
            throw new RuntimeException('envelope_key_conversion_failed', 0, $e);
        }

        $header = self::MAGIC . pack('N', strlen($sender->id)) . $sender->id . $nonce;
        $signature = $sender->sign($header . $cipher);

        return $header . pack('N', strlen($signature)) . $signature . $cipher;
    }

    /** @return array{sender_id:string,payload:string} */
    public static function open(string $binary, NodeIdentity $recipient, callable $publicKeyResolver): array
    {
        self::requireSodium();
        $minimum = strlen(self::MAGIC) + 4 + 1 + SODIUM_CRYPTO_BOX_NONCEBYTES + 4
            + SODIUM_CRYPTO_SIGN_BYTES + SODIUM_CRYPTO_BOX_MACBYTES;
        if (strlen($binary) < $minimum || !str_starts_with($binary, self::MAGIC)) {
            throw new RuntimeException('envelope_invalid');
        }
        if (strlen($binary) > self::MAX_PAYLOAD_BYTES + $minimum + self::MAX_SENDER_ID_BYTES) {
            throw new RuntimeException('envelope_too_large');
        }

        $offset = strlen(self::MAGIC);
        $idLength = self::readUint32($binary, $offset);
        if ($idLength < 1 || $idLength > self::MAX_SENDER_ID_BYTES) {
            throw new RuntimeException('envelope_sender_id_invalid');
        }
        self::requireAvailable($binary, $offset, $idLength + SODIUM_CRYPTO_BOX_NONCEBYTES + 4);

        $senderId = substr($binary, $offset, $idLength);
        $offset += $idLength;
        if (!preg_match('/^[A-Za-z0-9._:-]{3,128}$/', $senderId)) {
            throw new RuntimeException('envelope_sender_id_invalid');
        }

        $nonce = substr($binary, $offset, SODIUM_CRYPTO_BOX_NONCEBYTES);
        $offset += SODIUM_CRYPTO_BOX_NONCEBYTES;
        $signatureLength = self::readUint32($binary, $offset);
        if ($signatureLength !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new RuntimeException('envelope_signature_length_invalid');
        }
        self::requireAvailable($binary, $offset, $signatureLength + SODIUM_CRYPTO_BOX_MACBYTES);

        $signature = substr($binary, $offset, $signatureLength);
        $offset += $signatureLength;
        $cipher = substr($binary, $offset);
        if (strlen($cipher) > self::MAX_PAYLOAD_BYTES + SODIUM_CRYPTO_BOX_MACBYTES) {
            throw new RuntimeException('envelope_payload_too_large');
        }

        $senderPublic = $publicKeyResolver($senderId);
        if (!is_string($senderPublic)) {
            throw new RuntimeException('sender_unknown');
        }
        self::assertPublicKey($senderPublic, 'sender_public_key_invalid');

        $headerLength = strlen(self::MAGIC) + 4 + $idLength + SODIUM_CRYPTO_BOX_NONCEBYTES;
        $header = substr($binary, 0, $headerLength);
        if (!NodeIdentity::verify($header . $cipher, $signature, $senderPublic)) {
            throw new RuntimeException('envelope_signature_invalid');
        }

        try {
            $recipientSecret = sodium_crypto_sign_ed25519_sk_to_curve25519($recipient->secretKey);
            $senderCurvePublic = sodium_crypto_sign_ed25519_pk_to_curve25519($senderPublic);
            $keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($recipientSecret, $senderCurvePublic);
            $plain = sodium_crypto_box_open($cipher, $nonce, $keypair);
        } catch (SodiumException $e) {
            throw new RuntimeException('envelope_key_conversion_failed', 0, $e);
        }
        if ($plain === false) {
            throw new RuntimeException('envelope_decrypt_failed');
        }

        return ['sender_id' => $senderId, 'payload' => $plain];
    }

    private static function requireSodium(): void
    {
        if (!extension_loaded('sodium')) throw new RuntimeException('sodium_required');
    }

    private static function assertPublicKey(string $key, string $error): void
    {
        if (strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) throw new RuntimeException($error);
    }

    private static function readUint32(string $binary, int &$offset): int
    {
        self::requireAvailable($binary, $offset, 4);
        $value = unpack('Nvalue', substr($binary, $offset, 4));
        $offset += 4;
        return (int) ($value['value'] ?? -1);
    }

    private static function requireAvailable(string $binary, int $offset, int $length): void
    {
        if ($length < 0 || $offset < 0 || $offset + $length > strlen($binary)) {
            throw new RuntimeException('envelope_truncated');
        }
    }
}
