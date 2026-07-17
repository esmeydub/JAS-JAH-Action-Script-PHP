<?php

declare(strict_types=1);

return [
    'domain' => 'Usuarios',
    'name' => 'usuario.register',
    'input' => 'UserCommand',
    'output' => 'UserResult',
    'capability' => 'users.create',
    'audit' => true,
    'idempotent' => true,
];
