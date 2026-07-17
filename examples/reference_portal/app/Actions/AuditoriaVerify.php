<?php

declare(strict_types=1);

return [
    'domain' => 'Auditoria',
    'name' => 'auditoria.verify',
    'input' => 'AuditQuery',
    'output' => 'AuditResult',
    'capability' => 'audit.verify',
    'audit' => true,
];
