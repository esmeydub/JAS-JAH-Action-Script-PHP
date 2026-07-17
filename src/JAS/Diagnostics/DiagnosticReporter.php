<?php

declare(strict_types=1);

namespace Jah\JAS\Diagnostics;

use Jah\JAS\Web\Response;

interface DiagnosticReporter
{
    public function report(Diagnostic $diagnostic, int $status): Response;
}
