<?php

declare(strict_types=1);

return [
    'domain' => 'Publicaciones',
    'name' => 'publicacion.publish',
    'input' => 'PostCommand',
    'output' => 'PostResult',
    'capability' => 'publications.create',
    'audit' => true,
    'idempotent' => true,
];
