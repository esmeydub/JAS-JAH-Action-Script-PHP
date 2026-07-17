<?php

declare(strict_types=1);

return [
    'name' => 'FeedResult',
    'fields' => [
        'id' => 'identifier',
        'posts' => 'PostView[]',
    ],
    'strict' => true,
];
