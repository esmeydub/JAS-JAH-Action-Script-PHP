<?php

declare(strict_types=1);

return [
    'name' => 'NotificationResult',
    'fields' => [
        'id' => 'identifier',
        'notifications' => 'NotificationView[]',
    ],
    'strict' => true,
];
