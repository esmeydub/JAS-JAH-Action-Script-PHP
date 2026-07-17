<?php

declare(strict_types=1);

// Referencia documental. La autoridad ejecutable reside en InstitutionalIdentityService.
return [
    'roles' => ['admin', 'citizen', 'moderator', 'auditor'],
    'session_transport' => 'bearer',
    'maximum_request_bytes' => 65_536,
];
