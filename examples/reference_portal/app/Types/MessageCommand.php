<?php

declare(strict_types=1);

return [
    'name' => 'MessageCommand',
    'fields' => [
        'id' => 'identifier',
        'recipient_id' => 'identifier',
        'body' => 'non-empty-string',
    ],
    'strict' => true,
];
