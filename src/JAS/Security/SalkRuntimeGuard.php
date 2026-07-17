<?php

declare(strict_types=1);

namespace Jah\JAS\Security;

use Jah\JAS\Protocol\JasPacket;

final class SalkRuntimeGuard
{
    public function __construct(private readonly ReplayGuard $replay, private readonly CapabilityPolicy $policy) {}

    public function authorize(JasPacket $packet, string $principal, string $capability): void
    {
        $this->replay->assertFresh($packet->requestId, $packet->timestamp);
        $this->policy->assertAllowed($principal, $capability);
    }
}
