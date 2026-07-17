<?php

declare(strict_types=1);

require_once __DIR__ . '/environment.php';

return [
    'enabled' => (bool) jah_env('SALK_ENABLED', true),
    'audit_file' => (string) jah_env('SALK_AUDIT_FILE', dirname(__DIR__, 2) . '/runtime/security/salk_audit.jahl'),
    'max_secret_scan_matches' => jah_int_env('SALK_MAX_SECRET_SCAN_MATCHES', 20),
    'protect' => [
        'datacore_paths' => true,
        'runtime_permissions' => true,
        'trace_masking' => true,
        'secret_memory_block' => true,
        'package_vectors' => true,
        'public_payloads' => true,
    ],
    'pure_php_mode' => [
        'enabled' => true,
        'internal_actions' => 'ActionScript PHP',
        'internal_config' => 'PHP arrays',
        'forbidden_for' => [
            'package runtime',
            'Node/npm execution',
            'internal action registry',
            'storing API keys',
            'public responses',
            'DataCore storage',
            'SALK audit',
        ],
    ],
];
