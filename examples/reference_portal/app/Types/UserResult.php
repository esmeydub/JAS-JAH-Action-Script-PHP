<?php

declare(strict_types=1);

return [
    'name' => 'UserResult',
    'fields' => [
        'id' => 'identifier',
        'username' => 'non-empty-string',
        'role' => 'identifier',
    ],
    'strict' => true,
];
