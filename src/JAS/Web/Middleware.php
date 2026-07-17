<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

interface Middleware
{
    /** @param callable(Request):Response $next */
    public function process(Request $request, callable $next): Response;
}
