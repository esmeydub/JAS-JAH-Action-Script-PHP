<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

interface Component
{
    public function render(): SafeHtml;
}
