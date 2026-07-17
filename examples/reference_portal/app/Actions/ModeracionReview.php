<?php

declare(strict_types=1);

return [
    'domain' => 'Moderacion',
    'name' => 'moderacion.review',
    'input' => 'ModerationCommand',
    'output' => 'ModerationResult',
    'capability' => 'moderation.review',
    'audit' => true,
];
