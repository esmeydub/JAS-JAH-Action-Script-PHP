<?php

declare(strict_types=1);

namespace Jah;

use InvalidArgumentException;

/**
 * Small, deterministic JAS bytecode encoder/interpreter.
 *
 * This is deliberately a JAS container, not a pretend native executable.
 */
final class JasBinaryCompiler
{
    private const MAGIC = 'JASB';
    private const VERSION = 1;
    private const OP_EXIT = 1;
    private const PAYLOAD_SIZE = 10;
    private const CHECKSUM_SIZE = 32;

    public static function compileExit(int $code): string
    {
        if ($code < -2147483648 || $code > 2147483647) {
            throw new InvalidArgumentException('Exit code must fit in a signed 32-bit integer');
        }

        $unsigned = $code < 0 ? $code + 4294967296 : $code;
        $payload = self::MAGIC
            . chr(self::VERSION)
            . chr(self::OP_EXIT)
            . pack('N', $unsigned);

        return $payload . hash('sha256', $payload, true);
    }

    public static function execute(string $binary): int
    {
        if (!self::validate($binary)) {
            throw new InvalidArgumentException('Invalid or corrupted JAS bytecode');
        }

        $opcode = ord($binary[5]);
        if ($opcode !== self::OP_EXIT) {
            throw new InvalidArgumentException('Unsupported JAS opcode');
        }

        $decoded = unpack('Ncode', substr($binary, 6, 4));
        $unsigned = (int) ($decoded['code'] ?? 0);
        return $unsigned > 2147483647 ? $unsigned - 4294967296 : $unsigned;
    }

    public static function validate(string $binary): bool
    {
        if (strlen($binary) !== self::PAYLOAD_SIZE + self::CHECKSUM_SIZE) {
            return false;
        }

        $payload = substr($binary, 0, self::PAYLOAD_SIZE);
        $checksum = substr($binary, self::PAYLOAD_SIZE);

        return substr($payload, 0, 4) === self::MAGIC
            && ord($payload[4]) === self::VERSION
            && ord($payload[5]) === self::OP_EXIT
            && hash_equals(hash('sha256', $payload, true), $checksum);
    }
}
