<?php

declare(strict_types=1);

namespace Jah\JAS\Diagnostics;

use Jah\JAS\Web\Request;
use Jah\JAS\Web\Response;
use Throwable;

final class ErrorBoundary
{
    public function __construct(
        private readonly ExceptionMapper $mapper,
        private readonly DiagnosticStore $store,
        private readonly DiagnosticReporter $reporter,
    ) {}

    public function handle(Throwable $error, ?Request $request = null, array $context = []): Response
    {
        if ($request !== null) {
            $context += [
                'method' => $request->method,
                'path' => $request->path,
                'action' => $request->attributes['route_action'] ?? null,
            ];
        }
        $diagnostic = $this->store->append($this->mapper->map($error, $context));
        return $this->reporter->report($diagnostic, $this->status($diagnostic));
    }

    private function status(Diagnostic $diagnostic): int
    {
        return match ($diagnostic->code) {
            DiagnosticCode::ROUTE_NOT_REGISTERED => 404,
            DiagnosticCode::INPUT_TYPE_MISMATCH, DiagnosticCode::HTML_ATTRIBUTE_NOT_ALLOWED,
            DiagnosticCode::UNSAFE_HTML_CONTENT, DiagnosticCode::STRICT_TYPES_MISSING => 422,
            DiagnosticCode::CAPABILITY_MISSING => 403,
            default => 500,
        };
    }
}
