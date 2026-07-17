<?php

declare(strict_types=1);

return [
    'domain' => 'Identidad',
    'name' => 'identidad.authenticate',
    'input' => 'CredentialInput',
    'output' => 'SessionOutput',
    'capability' => 'identity.login',
    'audit' => true,
];
