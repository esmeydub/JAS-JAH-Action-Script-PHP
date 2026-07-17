<?php

declare(strict_types=1);
namespace Jah\JAS\Cluster;
final class LeaderElector
{
    public function __construct(private readonly NodeRegistry $registry) {}
    public function leader(): ?array { $nodes=$this->registry->all(true); if($nodes===[]) return null; ksort($nodes,SORT_STRING); return reset($nodes) ?: null; }
    public function isLeader(string $nodeId): bool { return ($this->leader()['id'] ?? null)===$nodeId; }
}
