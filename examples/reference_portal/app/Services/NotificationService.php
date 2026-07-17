<?php

declare(strict_types=1);

namespace JAS\ReferencePortal;

use Jah\DataCore\DataCoreDatabase;
use Jah\JAS\Queue\Job;
use Jah\JAS\Queue\PersistentJobQueue;

final class NotificationService
{
    public function __construct(
        private readonly DataCoreDatabase $database,
        private readonly PersistentJobQueue $queue,
    ) {}

    public function notify(string $userId, string $kind, string $subjectId): string
    {
        $id = 'NOTICE-' . bin2hex(random_bytes(12));
        $this->database->insert('portal_notifications', [
            'id' => $id, 'user_lookup' => hash('sha256', $userId), 'user_id' => $userId,
            'kind' => $kind, 'subject_id' => $subjectId, 'read' => false, 'created_at' => time(),
        ]);
        $this->queue->submit(Job::create(
            'notification.deliver', ['notification_id' => $id], 'notifications.deliver',
            0, 5, $id, 'notification:' . $id,
        ));
        return $id;
    }

    public function list(string $userId, int $limit): array
    {
        $rows = $this->database->findByIndex(
            'portal_notifications', 'notifications_by_user', ['user_lookup' => hash('sha256', $userId)], $limit,
        );
        return array_map(static fn(array $row): array => [
            'id' => $row['id'], 'kind' => $row['kind'], 'subject_id' => $row['subject_id'],
        ], $rows);
    }
}
