<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

interface UploadScanner
{
    /** Debe lanzar una excepción si el contenido es peligroso o el escáner no está disponible. */
    public function assertSafe(string $path, string $mime, string $sha256): void;
}
