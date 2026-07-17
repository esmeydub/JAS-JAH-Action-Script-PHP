<?php

declare(strict_types=1);

namespace Jah\JAS\Protocol;

use RuntimeException;

/** Canonical binary values for the C++ bridge boundary; never JSON or PHP serialization. */
final class LanguagePayloadCodec
{
    private const MAGIC = 'JASL';
    private const VERSION = 1;
    private const NULL = 0;
    private const FALSE = 1;
    private const TRUE = 2;
    private const INTEGER = 3;
    private const STRING = 4;
    private const LIST = 5;
    private const MAP = 6;
    private const MAX_BYTES = 8_388_608;
    private const MAX_DEPTH = 16;
    private const MAX_ITEMS = 4_096;

    public function encode(array $value): string
    {
        $encoded = self::MAGIC . chr(self::VERSION) . $this->encodeValue($value, 0);
        if (strlen($encoded) > self::MAX_BYTES) throw new RuntimeException('language_payload_too_large');
        return $encoded;
    }

    public function decode(string $binary): array
    {
        if (strlen($binary) < 10 || strlen($binary) > self::MAX_BYTES
            || substr($binary, 0, 4) !== self::MAGIC || ord($binary[4]) !== self::VERSION) {
            throw new RuntimeException('language_payload_header_invalid');
        }
        $offset = 5;
        $value = $this->decodeValue($binary, $offset, 0);
        if ($offset !== strlen($binary) || !is_array($value) || array_is_list($value)) {
            throw new RuntimeException('language_payload_trailing_data');
        }
        return $value;
    }

    private function encodeValue(mixed $value, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) throw new RuntimeException('language_payload_depth_exceeded');
        if ($value === null) return chr(self::NULL) . pack('N', 0);
        if ($value === false) return chr(self::FALSE) . pack('N', 0);
        if ($value === true) return chr(self::TRUE) . pack('N', 0);
        if (is_int($value)) {
            if ($value < -2_147_483_648 || $value > 2_147_483_647) throw new RuntimeException('language_payload_integer_invalid');
            return chr(self::INTEGER) . pack('N', 4) . pack('N', $value);
        }
        if (is_string($value)) {
            if (strlen($value) > 4_194_304 || preg_match('//u', $value) !== 1) throw new RuntimeException('language_payload_string_invalid');
            return chr(self::STRING) . pack('N', strlen($value)) . $value;
        }
        if (!is_array($value) || count($value) > self::MAX_ITEMS) throw new RuntimeException('language_payload_type_invalid');
        if (array_is_list($value)) {
            $body = pack('n', count($value));
            foreach ($value as $item) $body .= $this->encodeValue($item, $depth + 1);
            return chr(self::LIST) . pack('N', strlen($body)) . $body;
        }
        $keys = array_keys($value);
        foreach ($keys as $key) {
            if (!is_string($key) || preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,63}$/', $key) !== 1) {
                throw new RuntimeException('language_payload_key_invalid');
            }
        }
        sort($keys, SORT_STRING);
        $body = pack('n', count($keys));
        foreach ($keys as $key) {
            $body .= chr(strlen($key)) . $key . $this->encodeValue($value[$key], $depth + 1);
        }
        return chr(self::MAP) . pack('N', strlen($body)) . $body;
    }

    private function decodeValue(string $binary, int &$offset, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH || $offset + 5 > strlen($binary)) throw new RuntimeException('language_payload_truncated');
        $type = ord($binary[$offset]);
        $length = $this->u32($binary, $offset + 1);
        $offset += 5;
        $end = $offset + $length;
        if ($end < $offset || $end > strlen($binary)) throw new RuntimeException('language_payload_truncated');
        if ($type === self::NULL || $type === self::FALSE || $type === self::TRUE) {
            if ($length !== 0) throw new RuntimeException('language_payload_scalar_length_invalid');
            return match ($type) { self::NULL => null, self::FALSE => false, default => true };
        }
        if ($type === self::INTEGER) {
            if ($length !== 4) throw new RuntimeException('language_payload_scalar_length_invalid');
            $value = $this->u32($binary, $offset);
            $offset = $end;
            return $value > 2_147_483_647 ? $value - 4_294_967_296 : $value;
        }
        if ($type === self::STRING) {
            $value = substr($binary, $offset, $length);
            $offset = $end;
            if (preg_match('//u', $value) !== 1) throw new RuntimeException('language_payload_string_invalid');
            return $value;
        }
        if (($type !== self::LIST && $type !== self::MAP) || $length < 2) throw new RuntimeException('language_payload_type_invalid');
        $count = $this->u16($binary, $offset);
        $offset += 2;
        if ($count > self::MAX_ITEMS) throw new RuntimeException('language_payload_items_exceeded');
        $value = [];
        for ($index = 0; $index < $count; $index++) {
            if ($type === self::LIST) {
                $value[] = $this->decodeValue($binary, $offset, $depth + 1);
                continue;
            }
            if ($offset >= $end) throw new RuntimeException('language_payload_truncated');
            $keyLength = ord($binary[$offset++]);
            if ($keyLength < 1 || $offset + $keyLength > $end) throw new RuntimeException('language_payload_key_invalid');
            $key = substr($binary, $offset, $keyLength);
            $offset += $keyLength;
            if (preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,63}$/', $key) !== 1 || array_key_exists($key, $value)) {
                throw new RuntimeException('language_payload_key_invalid');
            }
            $value[$key] = $this->decodeValue($binary, $offset, $depth + 1);
        }
        if ($offset !== $end) throw new RuntimeException('language_payload_container_length_invalid');
        return $value;
    }

    private function u16(string $binary, int $offset): int
    {
        $value = unpack('nvalue', substr($binary, $offset, 2));
        if (!is_array($value)) throw new RuntimeException('language_payload_truncated');
        return (int) $value['value'];
    }

    private function u32(string $binary, int $offset): int
    {
        $value = unpack('Nvalue', substr($binary, $offset, 4));
        if (!is_array($value)) throw new RuntimeException('language_payload_truncated');
        return (int) $value['value'];
    }
}
