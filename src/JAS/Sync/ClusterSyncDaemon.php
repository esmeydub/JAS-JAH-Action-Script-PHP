<?php

declare(strict_types=1);

namespace Jah\JAS\Sync;

use Jah\JAS\Cluster\ClusterMessageRouter;
use Jah\JAS\Cluster\NodeIdentity;
use Jah\JAS\Cluster\NodeRegistry;
use Jah\JAS\Server\TcpClusterClient;

final class ClusterSyncDaemon
{
    public function __construct(
        private readonly NodeIdentity $identity,
        private readonly NodeRegistry $registry,
        private readonly ClusterMessageRouter $router,
        private readonly TcpClusterClient $client,
        private readonly SyncCursorStore $cursors,
        private readonly array $streams = ['queue'],
        private readonly int $intervalSeconds = 5
    ) {}

    public function tick(): array
    {
        $this->registry->heartbeat($this->identity, ['pid' => getmypid(), 'role' => 'sync']);
        $stats = ['peers' => 0, 'imported' => 0, 'errors' => [], 'cursors' => []];

        foreach ($this->registry->all(true) as $id => $node) {
            if ($id === $this->identity->id) {
                continue;
            }
            $stats['peers']++;
            foreach ($this->streams as $stream) {
                $stream = trim((string) $stream);
                if ($stream === '') {
                    continue;
                }
                try {
                    $after = $this->cursors->get($id, $stream);
                    $request = $this->router->encodeFor($id, [
                        'type' => 'REPLICATION_EXPORT',
                        'stream' => $stream,
                        'origin_node' => $id,
                        'after_seq' => $after,
                    ]);
                    $raw = $this->client->request((string) $node['endpoint'], $request);
                    $reply = $this->router->decode($raw);
                    if (($reply['success'] ?? false) !== true) {
                        throw new \RuntimeException((string) ($reply['error'] ?? 'replication_export_failed'));
                    }

                    $rows = (array) ($reply['rows'] ?? []);
                    if ($rows !== []) {
                        $importRequest = $this->router->encodeFor($this->identity->id, [
                            'type' => 'REPLICATION_IMPORT',
                            'rows' => $rows,
                        ]);
                        $localReply = $this->router->decode($this->router->handle($importRequest));
                        $stats['imported'] += (int) ($localReply['imported'] ?? 0);
                        $last = max(array_map(static fn(array $row): int => (int) ($row['seq'] ?? 0), $rows));
                        $this->cursors->advance($id, $stream, $last);
                    }
                    $stats['cursors'][$id . ':' . $stream] = $this->cursors->get($id, $stream);
                } catch (\Throwable $e) {
                    $stats['errors'][$id . ':' . $stream] = $e->getMessage();
                }
            }
        }

        return $stats;
    }

    public function run(int $iterations = 0): void
    {
        $i = 0;
        while ($iterations === 0 || $i < $iterations) {
            $this->tick();
            $i++;
            if ($iterations === 0 || $i < $iterations) {
                sleep(max(1, $this->intervalSeconds));
            }
        }
    }
}
