<?php

declare(strict_types=1);

return [
    'name' => 'SessionOutput',
    'fields' => [
        'id' => 'identifier',
        'status' => 'non-empty-string',
        'token' => 'non-empty-string',
        'user_id' => 'identifier',
    ],
    'strict' => true,
];
