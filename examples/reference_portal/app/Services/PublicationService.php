<?php

declare(strict_types=1);

namespace JAS\ReferencePortal;

use Jah\DataCore\DataCoreDatabase;
use Jah\JAS\Queue\Job;
use Jah\JAS\Queue\PersistentJobQueue;
use RuntimeException;

final class PublicationService
{
    public function __construct(
        private readonly DataCoreDatabase $database,
        private readonly PersistentJobQueue $feedQueue,
        private readonly PersistentJobQueue $moderationQueue,
    ) {}

    public function publish(string $author, array $command): array
    {
        $id = (string) $command['id'];
        $this->database->insert('portal_posts', [
            'id' => $id, 'author_id' => $author, 'content' => trim((string) $command['content']),
            'status' => 'pending', 'created_at' => time(),
        ]);
        $payload = ['post_id' => $id, 'author_id' => $author];
        $this->feedQueue->submit(Job::create('feed.project', $payload, 'feeds.project', 0, 5, $id, 'feed:' . $id));
        $this->moderationQueue->submit(Job::create('moderation.inspect', $payload, 'moderation.inspect', 10, 5, $id, 'moderation:' . $id));
        return ['id' => $id, 'status' => 'pending'];
    }

    public function approved(int $limit): array
    {
        $posts = $this->database->findByIndex('portal_posts', 'posts_by_status', ['status' => 'approved'], $limit);
        return array_map(static fn(array $post): array => [
            'id' => $post['id'], 'author_id' => $post['author_id'],
            'content' => $post['content'], 'status' => $post['status'],
        ], $posts);
    }

    public function review(string $id, string $decision): array
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) throw new RuntimeException('portal_moderation_decision_invalid');
        $post = $this->database->find('portal_posts', $id) ?? throw new RuntimeException('portal_post_not_found');
        if ((string) $post['status'] !== 'pending') throw new RuntimeException('portal_post_already_reviewed');
        $document = [
            'id' => $post['id'], 'author_id' => $post['author_id'], 'content' => $post['content'],
            'status' => $decision, 'created_at' => $post['created_at'],
        ];
        $this->database->update('portal_posts', $id, $document, (int) $post['_version']);
        return ['id' => $id, 'status' => $decision, 'author_id' => (string) $post['author_id']];
    }
}
