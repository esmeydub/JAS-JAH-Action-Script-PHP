<?php

declare(strict_types=1);

namespace Jah\JAS\Protocol;

use InvalidArgumentException;

final class JasPacket
{
    public const MAGIC = 'JASB';
    public const VERSION = 2;
    public const HEADER_SIZE = 36;
    public const SIGNATURE_SIZE = 32;
    public const MAX_PAYLOAD_SIZE = 16_777_216;

    public function __construct(
        public readonly int $opcode,
        public readonly int $flags,
        public readonly string $requestId,
        public readonly string $objectId,
        public readonly string $payload,
        public readonly int $timestamp,
        public readonly string $signature = ''
    ) {
        if ($opcode < 0 || $opcode > 65535) {
            throw new InvalidArgumentException('Opcode fuera de rango');
        }
        if ($flags < 0 || $flags > 65535) {
            throw new InvalidArgumentException('Flags fuera de rango');
        }
        if ($timestamp < 0 || $timestamp > 4_294_967_295) {
            throw new InvalidArgumentException('Timestamp fuera de rango');
        }
        if (strlen($payload) > self::MAX_PAYLOAD_SIZE) {
            throw new InvalidArgumentException('Payload JAS fuera de límite');
        }
        foreach (['requestId' => $requestId, 'objectId' => $objectId] as $name => $value) {
            if ($value === '' || strlen($value) > 255) {
                throw new InvalidArgumentException("{$name} debe tener entre 1 y 255 bytes");
            }
        }
    }
}
