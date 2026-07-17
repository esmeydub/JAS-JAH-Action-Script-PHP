<?php

declare(strict_types=1);

namespace Jah\JAS;

use Jah\JAS\Definition\JasApplication;

final class Jas
{
    public static function application(string $name): JasApplication
    {
        return new JasApplication($name);
    }
}
