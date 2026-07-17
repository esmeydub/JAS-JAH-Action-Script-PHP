<?php

declare(strict_types=1);

namespace Jah\JAS\Runtime;

use Jah\JAS\Persistence\EventCursorStore;
use Jah\JAS\Persistence\EventJournal;
use Jah\JAS\Persistence\EventReceiptStore;
use RuntimeException;

final class EventProcessor
{
    /** @var array<string,array{versions:list<int>,handler:callable}> */
    private array $subscriptions = [];

    public function __construct(
        private readonly string $consumer,
        private readonly EventJournal $journal,
        private readonly EventCursorStore $cursors,
        private readonly EventReceiptStore $receipts
    ) {}

    public function on(string $event, callable $handler, array $versions = [1]): self
    {
        if (!preg_match('/^[a-z][a-z0-9_.:-]{2,255}$/', $event)) throw new RuntimeException('event_subscription_invalid');
        $versions = array_values(array_unique(array_map('intval', $versions)));
        if ($versions === [] || min($versions) < 1) throw new RuntimeException('event_versions_invalid');
        $this->subscriptions[$event] = ['versions' => $versions, 'handler' => $handler];
        return $this;
    }

    public function run(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 10_000) throw new RuntimeException('event_batch_limit_invalid');
        if (!$this->journal->verify()) throw new RuntimeException('event_journal_integrity_failed');
        $cursor = $this->cursors->get($this->consumer);
        $processed = 0;
        foreach ($this->journal->all() as $event) {
            $sequence = (int) ($event['sequence'] ?? 0);
            if ($sequence <= $cursor) continue;
            if ($sequence !== $cursor + 1) throw new RuntimeException('event_sequence_gap');
            $subscription = $this->subscriptions[(string) ($event['name'] ?? '')] ?? null;
            if ($subscription !== null) {
                if (!in_array((int) ($event['version'] ?? 0), $subscription['versions'], true)) {
                    throw new RuntimeException('event_version_not_supported');
                }
                $executed = $this->receipts->processOnce(
                    $this->consumer,
                    (string) ($event['id'] ?? ''),
                    fn() => ($subscription['handler'])((array) ($event['payload'] ?? []), $event)
                );
                if ($executed) $processed++;
            }
            $this->cursors->advance($this->consumer, $sequence);
            $cursor = $sequence;
            if ($processed >= $limit) break;
        }
        return $processed;
    }
}
