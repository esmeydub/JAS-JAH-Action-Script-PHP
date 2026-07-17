<?php

declare(strict_types=1);

namespace JAS\ReferencePortal;

use Jah\JAS\Persistence\AuditJournal;

final class AuditService
{
    public function __construct(private readonly AuditJournal $audit) {}

    public function verify(array $query): array
    {
        return ['id' => $query['id'], 'valid' => $this->audit->verify()];
    }
}
