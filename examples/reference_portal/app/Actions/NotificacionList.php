<?php

declare(strict_types=1);

return [
    'domain' => 'Notificaciones',
    'name' => 'notificacion.list',
    'input' => 'NotificationQuery',
    'output' => 'NotificationResult',
    'capability' => 'notifications.read',
    'audit' => true,
];
