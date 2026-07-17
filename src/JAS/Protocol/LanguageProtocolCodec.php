<?php

declare(strict_types=1);

namespace Jah\JAS\Protocol;

use RuntimeException;

final class LanguageProtocolCodec
{
    public function __construct(
        private readonly JasBinaryCodec $jasb,
        private readonly LanguagePayloadCodec $payload = new LanguagePayloadCodec(),
    ) {}

    public function encode(LanguageMessage $message, string $correlationId, string $sessionId, ?int $timestamp = null): string
    {
        return $this->jasb->encode(new JasPacket(
            $message->opcode(), 0, $correlationId, $sessionId,
            $this->payload->encode($message->toArray()), $timestamp ?? time(),
        ));
    }

    /** @return array{message:LanguageMessage,correlation_id:string,session_id:string,timestamp:int} */
    public function decode(string $packet): array
    {
        $decoded = $this->jasb->decode($packet, 8_388_608);
        $message = LanguageMessage::fromArray($this->payload->decode($decoded->payload));
        if ($message->opcode() !== $decoded->opcode) throw new RuntimeException('language_opcode_mismatch');
        return [
            'message' => $message, 'correlation_id' => $decoded->requestId,
            'session_id' => $decoded->objectId, 'timestamp' => $decoded->timestamp,
        ];
    }
}
