<?php

declare(strict_types=1);

namespace Jah\JAS\Observability;

interface TelemetryAdapter
{
    /** Receives one signed JASB packet and must transport it out of process. */
    public function send(string $packet): void;
}
