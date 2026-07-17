<?php

declare(strict_types=1);

return [
    'name' => 'UserCommand',
    'fields' => [
        'id' => 'identifier',
        'username' => 'non-empty-string',
        'display_name' => 'non-empty-string',
        'password' => 'non-empty-string',
        'role' => 'identifier',
    ],
    'strict' => true,
];
