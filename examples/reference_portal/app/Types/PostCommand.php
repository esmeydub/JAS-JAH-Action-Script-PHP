<?php

declare(strict_types=1);

return [
    'name' => 'PostCommand',
    'fields' => [
        'id' => 'identifier',
        'content' => 'non-empty-string',
    ],
    'strict' => true,
];
