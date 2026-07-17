<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use Jah\JAS\Security\KeyRing;
use RuntimeException;
use Throwable;

final class SecureCookieJar
{
    /** @var array<string,array{type:string,ttl:int,same_site:string}> */
    private array $definitions = [];

    public function __construct(private readonly KeyRing $keys, private readonly string $prefix = '__Host-JAS-')
    {
        if (!preg_match('/^__Host-[A-Za-z0-9_-]{1,48}-$/', $prefix)) throw new RuntimeException('cookie_prefix_invalid');
    }

    public function define(string $name, string $type, int $ttlSeconds, string $sameSite = 'Strict'): self
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{1,47}$/', $name)) throw new RuntimeException('cookie_definition_name_invalid');
        if (!in_array($type, ['string', 'non-empty-string', 'identifier', 'int', 'positive-int', 'bool'], true)) {
            throw new RuntimeException('cookie_definition_type_invalid');
        }
        if ($ttlSeconds < 60 || $ttlSeconds > 2_592_000) throw new RuntimeException('cookie_definition_ttl_invalid');
        if (!in_array($sameSite, ['Strict', 'Lax'], true)) throw new RuntimeException('cookie_definition_same_site_invalid');
        if (isset($this->definitions[$name])) throw new RuntimeException('cookie_definition_duplicated');
        $this->definitions[$name] = ['type' => $type, 'ttl' => $ttlSeconds, 'same_site' => $sameSite];
        return $this;
    }

    public function issue(string $name, mixed $value, ?int $now = null): Cookie
    {
        $definition = $this->definition($name);
        $now ??= time();
        $encoded = $this->encodeValue($definition['type'], $value);
        $expiresAt = $now + $definition['ttl'];
        $plaintext = implode("\n", ['JASC1', $name, $definition['type'], (string) $now, (string) $expiresAt, $encoded]);
        $sealed = $this->keys->encrypt($this->purpose($name), $plaintext);
        $token = 'JASC1.' . self::base64UrlEncode($sealed['key_id']) . '.' . self::base64UrlEncode($sealed['ciphertext']);
        return new Cookie($this->physicalName($name), $token, $expiresAt, $definition['ttl'], $definition['same_site']);
    }

    public function read(string $name, array $cookies, ?int $now = null): mixed
    {
        $definition = $this->definition($name);
        $token = $cookies[$this->physicalName($name)] ?? null;
        if ($token === null) return null;
        if (!is_string($token) || strlen($token) > 4_096) throw new RuntimeException('secure_cookie_invalid');
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3 || $parts[0] !== 'JASC1') throw new RuntimeException('secure_cookie_invalid');
            $keyId = self::base64UrlDecode($parts[1]);
            $ciphertext = self::base64UrlDecode($parts[2]);
            $plaintext = $this->keys->decrypt($this->purpose($name), $keyId, $ciphertext);
            $payload = explode("\n", $plaintext, 6);
            if (count($payload) !== 6 || $payload[0] !== 'JASC1' || $payload[1] !== $name || $payload[2] !== $definition['type']) {
                throw new RuntimeException('secure_cookie_invalid');
            }
            if (!ctype_digit($payload[3]) || !ctype_digit($payload[4])) throw new RuntimeException('secure_cookie_invalid');
            $issuedAt = (int) $payload[3];
            $expiresAt = (int) $payload[4];
            $now ??= time();
            if ($issuedAt > $now + 60 || $expiresAt <= $now || $expiresAt - $issuedAt !== $definition['ttl']) {
                throw new RuntimeException('secure_cookie_expired');
            }
            return $this->decodeValue($definition['type'], $payload[5]);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'secure_cookie_expired') throw $exception;
            throw new RuntimeException('secure_cookie_invalid');
        } catch (Throwable) {
            throw new RuntimeException('secure_cookie_invalid');
        }
    }

    public function forget(string $name, ?int $now = null): Cookie
    {
        $definition = $this->definition($name);
        $now ??= time();
        return new Cookie($this->physicalName($name), '', $now - 3600, 0, $definition['same_site']);
    }

    private function definition(string $name): array
    {
        return $this->definitions[$name] ?? throw new RuntimeException('cookie_definition_not_found');
    }

    private function physicalName(string $name): string { return $this->prefix . $name; }

    private function purpose(string $name): string { return 'jas.web.cookie.' . $name; }

    private function encodeValue(string $type, mixed $value): string
    {
        return match ($type) {
            'string' => is_string($value) ? self::base64UrlEncode($value) : throw new RuntimeException('cookie_value_type_invalid'),
            'non-empty-string' => is_string($value) && trim($value) !== '' ? self::base64UrlEncode($value) : throw new RuntimeException('cookie_value_type_invalid'),
            'identifier' => is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/', $value) === 1 ? $value : throw new RuntimeException('cookie_value_type_invalid'),
            'int' => is_int($value) ? (string) $value : throw new RuntimeException('cookie_value_type_invalid'),
            'positive-int' => is_int($value) && $value > 0 ? (string) $value : throw new RuntimeException('cookie_value_type_invalid'),
            'bool' => is_bool($value) ? ($value ? '1' : '0') : throw new RuntimeException('cookie_value_type_invalid'),
        };
    }

    private function decodeValue(string $type, string $value): mixed
    {
        return match ($type) {
            'string' => self::base64UrlDecode($value),
            'non-empty-string' => ($decoded = self::base64UrlDecode($value)) !== '' ? $decoded : throw new RuntimeException('secure_cookie_invalid'),
            'identifier' => preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/', $value) === 1 ? $value : throw new RuntimeException('secure_cookie_invalid'),
            'int' => preg_match('/^-?(?:0|[1-9][0-9]*)$/', $value) === 1 ? (int) $value : throw new RuntimeException('secure_cookie_invalid'),
            'positive-int' => preg_match('/^[1-9][0-9]*$/', $value) === 1 ? (int) $value : throw new RuntimeException('secure_cookie_invalid'),
            'bool' => match ($value) { '1' => true, '0' => false, default => throw new RuntimeException('secure_cookie_invalid') },
        };
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value)) throw new RuntimeException('secure_cookie_invalid');
        $padding = (4 - (strlen($value) % 4)) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
        if ($decoded === false) throw new RuntimeException('secure_cookie_invalid');
        return $decoded;
    }
}
