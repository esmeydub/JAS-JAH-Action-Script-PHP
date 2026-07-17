<?php

declare(strict_types=1);

namespace JAS\ReferencePortal;

final class ModerationService
{
    public function __construct(
        private readonly PublicationService $publications,
        private readonly NotificationService $notifications,
    ) {}

    public function review(array $command): array
    {
        $review = $this->publications->review((string) $command['id'], strtolower((string) $command['decision']));
        $this->notifications->notify((string) $review['author_id'], 'publication.' . $review['status'], (string) $review['id']);
        return ['id' => $review['id'], 'status' => $review['status']];
    }
}
