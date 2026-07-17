<?php

declare(strict_types=1);

namespace Jah\JAS\Diagnostics;

use Throwable;

final class ExceptionMapper
{
    public function map(Throwable $error, array $context = []): Diagnostic
    {
        if ($error instanceof DiagnosticException) return $error->diagnostic();
        $message = $error->getMessage();
        if (preg_match('/^(input|output)_type_mismatch:([A-Za-z][A-Za-z0-9_]*)$/', $message, $match) === 1) {
            return DiagnosticFactory::typeMismatch($match[1], $match[2], 'contract_mismatch', isset($context['action']) ? (string) $context['action'] : null)->diagnostic();
        }
        if ($message === 'action_handler_not_registered') {
            return DiagnosticFactory::actionNotRegistered((string) ($context['action'] ?? 'unknown'))->diagnostic();
        }
        if (preg_match('/^SALK denegó (.+) para (.+)$/', $message, $match) === 1) {
            return DiagnosticFactory::capabilityMissing($match[1], $match[2])->diagnostic();
        }
        if ($message === 'html_attribute_not_allowed') {
            return DiagnosticFactory::htmlAttributeNotAllowed((string) ($context['element'] ?? 'unknown'), (string) ($context['attribute'] ?? 'unknown'))->diagnostic();
        }
        if ($message === 'strict_types_missing') {
            return DiagnosticFactory::strictTypesMissing((string) ($context['file'] ?? 'unknown.php'), (int) ($context['line'] ?? 1))->diagnostic();
        }
        return DiagnosticFactory::unhandled((string) ($context['component'] ?? 'JAS'))->diagnostic();
    }
}
