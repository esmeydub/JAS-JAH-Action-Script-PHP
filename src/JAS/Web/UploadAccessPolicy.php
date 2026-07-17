<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

interface UploadAccessPolicy
{
    /** Recibe metadatos ya verificados de DataCore; debe decidir sin revelar el contenido. */
    public function canDownload(string $principalId, array $metadata): bool;
}
