<?php

declare(strict_types=1);

namespace Jah\JAS\Security;

use RuntimeException;

final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secret(int $bytes = 20): string
    {
        if ($bytes < 16 || $bytes > 64) throw new RuntimeException('totp_secret_size_invalid');
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $secret, ?int $at = null, int $period = 30, int $digits = 6): string
    {
        if ($period < 15 || $period > 120 || $digits < 6 || $digits > 8) {
            throw new RuntimeException('totp_parameters_invalid');
        }
        $key = self::base32Decode($secret);
        $counter = intdiv($at ?? time(), $period);
        $binaryCounter = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $number = (
            ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff)
        ) % (10 ** $digits);
        return str_pad((string) $number, $digits, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $code, ?int $at = null, int $window = 1): bool
    {
        if (!preg_match('/^[0-9]{6}$/', $code) || $window < 0 || $window > 2) return false;
        $at ??= time();
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::code($secret, $at + ($offset * 30)), $code)) return true;
        }
        return false;
    }

    private static function base32Encode(string $binary): string
    {
        $bits = '';
        foreach (str_split($binary) as $byte) $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $encoded;
    }

    private static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(rtrim($encoded, '='));
        if ($encoded === '' || preg_match('/[^A-Z2-7]/', $encoded)) {
            throw new RuntimeException('totp_secret_invalid');
        }
        $bits = '';
        foreach (str_split($encoded) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position === false) throw new RuntimeException('totp_secret_invalid');
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $binary = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) $binary .= chr(bindec($chunk));
        }
        return $binary;
    }
}
