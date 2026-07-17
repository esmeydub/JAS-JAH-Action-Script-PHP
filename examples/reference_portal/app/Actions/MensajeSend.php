<?php

declare(strict_types=1);

return [
    'domain' => 'Mensajeria',
    'name' => 'mensaje.send',
    'input' => 'MessageCommand',
    'output' => 'MessageResult',
    'capability' => 'messages.send',
    'audit' => true,
    'idempotent' => true,
];
