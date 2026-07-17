<?php

declare(strict_types=1);

namespace Jah\JAS\Diagnostics;

use Jah\JAS\Web\Response;

final class ProductionDiagnosticReporter implements DiagnosticReporter
{
    public function report(Diagnostic $diagnostic, int $status): Response
    {
        return new Response("The request could not be processed.\nIncident: {$diagnostic->id}\n", $status, 'text/plain; charset=utf-8', ['X-JAS-Incident' => $diagnostic->id]);
    }
}
