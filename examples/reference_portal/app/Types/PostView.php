<?php

declare(strict_types=1);

return [
    'name' => 'PostView',
    'fields' => [
        'id' => 'identifier',
        'author_id' => 'identifier',
        'content' => 'non-empty-string',
        'status' => 'non-empty-string',
    ],
    'strict' => true,
];
