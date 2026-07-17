<?php

declare(strict_types=1);

return [
    'name' => 'ModerationCommand',
    'fields' => [
        'id' => 'identifier',
        'decision' => 'non-empty-string',
    ],
    'strict' => true,
];
