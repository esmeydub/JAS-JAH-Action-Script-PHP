<?php

declare(strict_types=1);

namespace Jah\JAS\Diagnostics;

use RuntimeException;

final class DiagnosticException extends RuntimeException
{
    public function __construct(private readonly Diagnostic $diagnostic)
    {
        parent::__construct($diagnostic->message);
    }

    public function diagnostic(): Diagnostic { return $this->diagnostic; }
}
