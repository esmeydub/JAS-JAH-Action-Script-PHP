<?php

declare(strict_types=1);

namespace JAS\ReferencePortal;

use Jah\DataCore\DataCoreDatabase;

final class MessageService
{
    public function __construct(
        private readonly DataCoreDatabase $database,
        private readonly NotificationService $notifications,
    ) {}

    public function send(string $sender, array $command): array
    {
        $id = (string) $command['id'];
        $recipient = (string) $command['recipient_id'];
        $this->database->insert('portal_messages', [
            'id' => $id, 'sender_id' => $sender, 'recipient_id' => $recipient,
            'body' => trim((string) $command['body']), 'created_at' => time(),
        ]);
        $this->notifications->notify($recipient, 'message.received', $id);
        return ['id' => $id, 'status' => 'accepted'];
    }
}
