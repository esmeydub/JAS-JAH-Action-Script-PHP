<?php

declare(strict_types=1);

namespace Jah\JAS\Sharding;

use Jah\JAS\Cluster\NodeRegistry;
use RuntimeException;

final class ShardMap
{
    public function __construct(
        private readonly NodeRegistry $registry,
        private readonly int $replicas = 2,
    ) {
    }

    public function shardFor(string $collection, string $id, int $shardCount = 64): int
    {
        if ($shardCount < 1) {
            throw new RuntimeException('invalid_shard_count');
        }
        $hashPrefix = substr(hash('sha256', $collection . "\0" . $id), 0, 8);
        return (int) (hexdec($hashPrefix) % $shardCount);
    }

    public function owners(string $collection, int $shard): array
    {
        $nodes = array_values($this->registry->all(true));
        if ($nodes === []) {
            throw new RuntimeException('no_live_nodes');
        }
        usort(
            $nodes,
            fn(array $left, array $right): int => strcmp(
                hash('sha256', $collection . ':' . $shard . ':' . $right['id']),
                hash('sha256', $collection . ':' . $shard . ':' . $left['id']),
            ),
        );
        return array_slice($nodes, 0, min($this->replicas, count($nodes)));
    }
}
