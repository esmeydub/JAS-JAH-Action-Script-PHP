<?php

declare(strict_types=1);

return [
    'name' => 'CredentialInput',
    'fields' => [
        'id' => 'identifier',
        'username' => 'non-empty-string',
        'password' => 'non-empty-string',
        'device_id' => 'identifier',
        'device_label' => 'non-empty-string',
    ],
    'strict' => true,
];
