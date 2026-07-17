<?php

declare(strict_types=1);

namespace Jah\JAS\Cluster;

use Jah\DataCore\PhpSerializer;
use Jah\JAS\Consensus\QuorumPrepareStore;
use Jah\JAS\Telemetry\MetricsRegistry;
use Jah\JAS\Transport\SalkEncryptedEnvelope;
use RuntimeException;

final class ClusterMessageRouter
{
    public function __construct(
        private readonly NodeIdentity $identity,
        private readonly NodeRegistry $registry,
        private readonly ClusterCoordinator $coordinator,
        private readonly ?QuorumPrepareStore $prepareStore = null,
        private readonly ?MetricsRegistry $metrics = null,
    ) {
    }

    public function encodeFor(string $recipientId, array $message): string
    {
        $node = $this->registry->get($recipientId, false);
        if ($node === null) {
            throw new RuntimeException('recipient_unknown');
        }

        $publicKey = base64_decode((string) $node['public_key'], true);
        if ($publicKey === false) {
            throw new RuntimeException('recipient_key_invalid');
        }

        return SalkEncryptedEnvelope::seal(
            PhpSerializer::encode($message),
            $this->identity,
            $publicKey,
        );
    }

    public function handle(string $envelope): string
    {
        $opened = $this->open($envelope);
        $message = PhpSerializer::decode($opened['payload']);
        if (!is_array($message)) {
            throw new RuntimeException('cluster_message_invalid');
        }

        $reply = match ((string) ($message['type'] ?? '')) {
            'PING' => [
                'success' => true,
                'type' => 'PONG',
                'node' => $this->identity->id,
                'status' => $this->coordinator->status(),
            ],
            'REPLICATION_EXPORT' => [
                'success' => true,
                'rows' => $this->coordinator->export(
                    (string) ($message['stream'] ?? 'queue'),
                    (int) ($message['after_seq'] ?? 0),
                    (string) ($message['origin_node'] ?? $this->identity->id),
                ),
            ],
            'REPLICATION_IMPORT' => [
                'success' => true,
                'imported' => $this->coordinator->import((array) ($message['rows'] ?? [])),
            ],
            'CLUSTER_STATUS' => [
                'success' => true,
                'status' => $this->coordinator->status(),
            ],
            'QUORUM_PREPARE' => $this->prepareStore !== null
                ? $this->prepareStore->prepare($message)
                : ['accepted' => false, 'error' => 'prepare_store_unavailable'],
            'METRICS_SNAPSHOT' => [
                'success' => true,
                'metrics' => $this->metrics?->snapshot() ?? [],
            ],
            default => ['success' => false, 'error' => 'cluster_message_unsupported'],
        };

        return $this->encodeFor($opened['sender_id'], $reply);
    }

    public function decode(string $envelope): array
    {
        $opened = $this->open($envelope);
        $data = PhpSerializer::decode($opened['payload']);
        if (!is_array($data)) {
            throw new RuntimeException('cluster_reply_invalid');
        }

        return $data;
    }

    private function open(string $envelope): array
    {
        return SalkEncryptedEnvelope::open(
            $envelope,
            $this->identity,
            function (string $id): ?string {
                $node = $this->registry->get($id, false);
                if ($node === null) {
                    return null;
                }

                $publicKey = base64_decode((string) $node['public_key'], true);
                return $publicKey === false ? null : $publicKey;
            },
        );
    }
}
