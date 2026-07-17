<?php

declare(strict_types=1);

namespace Jah\JAS\Protocol;

use Jah\JAS\Security\SalkPacketGuard;
use InvalidArgumentException;

final class JasBinaryCodec
{
    public function __construct(private readonly SalkPacketGuard $salk) {}

    public function encode(JasPacket $packet): string
    {
        $requestLength = strlen($packet->requestId);
        $objectLength = strlen($packet->objectId);
        $payloadLength = strlen($packet->payload);
        if ($payloadLength > JasPacket::MAX_PAYLOAD_SIZE) {
            throw new InvalidArgumentException('Payload JAS fuera de límite');
        }

        $header = JasPacket::MAGIC
            . chr(JasPacket::VERSION)
            . chr(0)
            . pack('n', $packet->opcode)
            . pack('n', $packet->flags)
            . pack('N', $packet->timestamp)
            . pack('N', $payloadLength)
            . chr($requestLength)
            . chr($objectLength)
            . str_repeat("\0", 16);

        $body = $header . $packet->requestId . $packet->objectId . $packet->payload;
        return $body . $this->salk->sign($body);
    }

    public function decode(string $binary, int $maxPayloadBytes = 16_777_216): JasPacket
    {
        if ($maxPayloadBytes < 0 || $maxPayloadBytes > JasPacket::MAX_PAYLOAD_SIZE) {
            throw new InvalidArgumentException('Límite de payload JAS inválido');
        }
        $maximumPacketBytes = JasPacket::HEADER_SIZE + 510 + $maxPayloadBytes + JasPacket::SIGNATURE_SIZE;
        if (strlen($binary) > $maximumPacketBytes) {
            throw new InvalidArgumentException('Paquete JAS fuera de límite');
        }
        if (strlen($binary) < JasPacket::HEADER_SIZE + JasPacket::SIGNATURE_SIZE) {
            throw new InvalidArgumentException('Paquete JAS incompleto');
        }

        $signature = substr($binary, -JasPacket::SIGNATURE_SIZE);
        $body = substr($binary, 0, -JasPacket::SIGNATURE_SIZE);
        if (!$this->salk->verify($body, $signature)) {
            throw new InvalidArgumentException('Firma SALK inválida');
        }

        if (substr($body, 0, 4) !== JasPacket::MAGIC || ord($body[4]) !== JasPacket::VERSION) {
            throw new InvalidArgumentException('Formato o versión JAS no compatible');
        }

        $meta = unpack('nopcode/nflags/Ntimestamp/Npayload_length/Crequest_length/Cobject_length', substr($body, 6, 14));
        if (!is_array($meta)) {
            throw new InvalidArgumentException('Cabecera JAS inválida');
        }

        $payloadLength = (int) $meta['payload_length'];
        if ($payloadLength < 0 || $payloadLength > $maxPayloadBytes) {
            throw new InvalidArgumentException('Payload JAS fuera de límite');
        }

        $requestLength = (int) $meta['request_length'];
        $objectLength = (int) $meta['object_length'];
        $expected = JasPacket::HEADER_SIZE + $requestLength + $objectLength + $payloadLength;
        if (strlen($body) !== $expected) {
            throw new InvalidArgumentException('Longitud JAS inconsistente');
        }

        $offset = JasPacket::HEADER_SIZE;
        $requestId = substr($body, $offset, $requestLength);
        $offset += $requestLength;
        $objectId = substr($body, $offset, $objectLength);
        $offset += $objectLength;
        $payload = substr($body, $offset, $payloadLength);

        return new JasPacket(
            (int) $meta['opcode'],
            (int) $meta['flags'],
            $requestId,
            $objectId,
            $payload,
            (int) $meta['timestamp'],
            $signature
        );
    }
}
