<?php

declare(strict_types=1);

namespace Jah\JAS\Diagnostics;

final class DiagnosticFactory
{
    public static function htmlAttributeNotAllowed(string $element, string $attribute, string $component = 'Html'): DiagnosticException
    {
        return self::make(DiagnosticCode::HTML_ATTRIBUTE_NOT_ALLOWED, 'HTML attribute not allowed', 'html_attribute_not_allowed', $component,
            ['element' => $element, 'attribute' => $attribute, 'action' => 'REMOVE_INLINE_OR_EVENT_ATTRIBUTE'], 'Use an allowlisted attribute and a registered CSS class.');
    }

    public static function unsafeHtmlContent(string $element, string $component = 'Html'): DiagnosticException
    {
        return self::make(DiagnosticCode::UNSAFE_HTML_CONTENT, 'Unsafe HTML content', 'unsafe_html_content', $component,
            ['element' => $element, 'action' => 'USE_SAFE_HTML_PRIMITIVES'], 'Construct content with Html::text() and Html::element().');
    }

    public static function typeMismatch(string $direction, string $contract, string $received = 'contract_mismatch', ?string $action = null): DiagnosticException
    {
        $input = $direction === 'input';
        return self::make($input ? DiagnosticCode::INPUT_TYPE_MISMATCH : DiagnosticCode::OUTPUT_TYPE_MISMATCH,
            $input ? 'Input type mismatch' : 'Output type mismatch', $direction . '_type_mismatch:' . $contract, 'GovernedRuntime',
            array_filter(['contract' => $contract, 'received' => $received, 'action' => $action, 'correction' => 'CONSTRUCT_DECLARED_CONTRACT'], static fn(mixed $v): bool => $v !== null),
            'Construct the value using the declared JAS contract.');
    }

    public static function actionNotRegistered(string $action): DiagnosticException
    {
        return self::make(DiagnosticCode::ACTION_NOT_REGISTERED, 'Action handler not registered', 'action_handler_not_registered', 'GovernedRuntime',
            ['action' => $action, 'correction' => 'REGISTER_ACTION_HANDLER'], 'Register one governed handler for the declared action.');
    }

    public static function capabilityMissing(string $capability, ?string $principal = null): DiagnosticException
    {
        return self::make(DiagnosticCode::CAPABILITY_MISSING, 'Required capability missing', 'capability_missing', 'CapabilityPolicy',
            array_filter(['capability' => $capability, 'principal' => $principal, 'correction' => 'GRANT_MINIMUM_CAPABILITY'], static fn(mixed $v): bool => $v !== null),
            'Grant the minimum declared capability through the authorization policy.');
    }

    public static function routeNotRegistered(string $method, string $path): DiagnosticException
    {
        return self::make(DiagnosticCode::ROUTE_NOT_REGISTERED, 'Route not registered', 'route_not_registered', 'Router',
            ['method' => $method, 'path' => $path, 'correction' => 'DECLARE_GOVERNED_ROUTE'], 'Declare the route and connect it to a governed action.');
    }

    public static function strictTypesMissing(string $file, int $line = 1): DiagnosticException
    {
        return self::make(DiagnosticCode::STRICT_TYPES_MISSING, 'Strict types declaration missing', 'strict_types_missing', 'ProjectAnalyzer',
            ['correction' => 'ADD_DECLARE_STRICT_TYPES'], 'Add declare(strict_types=1) immediately after the PHP opening tag.', $file, $line);
    }

    public static function coreIntegrityViolation(string $file, string $reason = 'modified'): DiagnosticException
    {
        return self::make(DiagnosticCode::CORE_INTEGRITY_VIOLATION, 'JAS core integrity violation', 'core_integrity_violation', 'CoreIntegrityGuard',
            ['file' => $file, 'reason' => $reason, 'correction' => 'RESTORE_VERIFIED_CORE'], 'Restore the sealed JAS core or perform an explicitly reviewed reseal.');
    }

    public static function unhandled(string $component = 'JAS'): DiagnosticException
    {
        return self::make(DiagnosticCode::UNHANDLED_RUNTIME_ERROR, 'Unhandled runtime error', 'unhandled_runtime_error', $component,
            ['correction' => 'INSPECT_INCIDENT_LOCALLY'], 'Inspect the incident through php bin/jas diagnose --last.');
    }

    private static function make(string $code, string $title, string $message, string $component, array $context, ?string $suggestion, ?string $file = null, ?int $line = null): DiagnosticException
    {
        return new DiagnosticException(new Diagnostic(
            'JAS-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(6))), $code, 'error', $title, $message,
            $component, $file, $line, $context, $suggestion, gmdate('c'),
        ));
    }
}
