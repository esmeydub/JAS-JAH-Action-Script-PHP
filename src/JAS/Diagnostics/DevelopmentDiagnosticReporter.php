<?php

declare(strict_types=1);

namespace Jah\JAS\Diagnostics;

use Jah\JAS\Web\Response;

final class DevelopmentDiagnosticReporter implements DiagnosticReporter
{
    public function report(Diagnostic $diagnostic, int $status): Response
    {
        return new Response((new AgentDiagnosticReporter())->render($diagnostic), $status, 'text/plain; charset=utf-8', ['X-JAS-Incident' => $diagnostic->id]);
    }
}
