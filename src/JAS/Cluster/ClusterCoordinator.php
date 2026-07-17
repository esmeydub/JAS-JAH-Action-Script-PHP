<?php

declare(strict_types=1);

namespace Jah\JAS\Cluster;

use Jah\JAS\Replication\ReplicatedQueueLog;

final class ClusterCoordinator
{
    public function __construct(
        private readonly NodeIdentity $identity,
        private readonly NodeRegistry $registry,
        private readonly LeaderElector $elector,
        private readonly ReplicatedQueueLog $replication
    ) {}

    public function heartbeat(array $metadata = []): void
    {
        $this->registry->heartbeat($this->identity, $metadata);
    }

    public function status(): array
    {
        return [
            'node_id' => $this->identity->id,
            'leader' => $this->elector->leader()['id'] ?? null,
            'is_leader' => $this->elector->isLeader($this->identity->id),
            'nodes' => array_values($this->registry->all(true)),
        ];
    }

    public function publish(string $stream, string $eventId, array $event): array
    {
        return $this->replication->append($stream, $eventId, $event);
    }

    public function export(string $stream, int $afterSeq = 0, ?string $originNode = null): array
    {
        return $this->replication->events($stream, $afterSeq, $originNode ?? $this->identity->id);
    }

    public function lastSequence(string $stream, string $originNode): int
    {
        return $this->replication->lastSequence($stream, $originNode);
    }

    public function import(array $rows): int
    {
        return $this->replication->import($rows);
    }
}
