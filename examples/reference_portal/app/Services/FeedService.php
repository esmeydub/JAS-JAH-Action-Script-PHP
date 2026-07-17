<?php

declare(strict_types=1);

namespace JAS\ReferencePortal;

final class FeedService
{
    public function __construct(private readonly PublicationService $publications) {}

    public function read(array $query): array
    {
        $limit = max(1, min(100, (int) $query['limit']));
        return ['id' => $query['id'], 'posts' => $this->publications->approved($limit)];
    }
}
