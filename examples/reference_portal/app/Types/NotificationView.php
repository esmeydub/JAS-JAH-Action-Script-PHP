<?php

declare(strict_types=1);

return [
    'name' => 'NotificationView',
    'fields' => [
        'id' => 'identifier',
        'kind' => 'non-empty-string',
        'subject_id' => 'identifier',
    ],
    'strict' => true,
];
