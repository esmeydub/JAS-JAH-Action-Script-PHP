<?php

declare(strict_types=1);

namespace Jah\JAS\Security;

use RuntimeException;

final class SalkPacketGuard
{
    public function __construct(private readonly string $key)
    {
        if (strlen($this->key) < 32) {
            throw new RuntimeException('SALK_PACKET_KEY debe contener al menos 32 bytes');
        }
    }

    public function sign(string $data): string
    {
        return hash_hmac('sha256', $data, $this->key, true);
    }

    public function verify(string $data, string $signature): bool
    {
        return strlen($signature) === 32
            && hash_equals($this->sign($data), $signature);
    }
}
