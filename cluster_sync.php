#!/usr/bin/env php
<?php

declare(strict_types=1);

$boot = require __DIR__ . '/app/bootstrap.php';
$root = $boot['root'];

use Jah\JAS\Cluster\{NodeIdentity, NodeRegistry, LeaderElector, ClusterCoordinator, ClusterMessageRouter};
use Jah\JAS\Replication\ReplicatedQueueLog;
use Jah\JAS\Server\TcpClusterClient;
use Jah\JAS\Transport\FrameProtocol;
use Jah\JAS\Sync\{ClusterSyncDaemon, SyncCursorStore};

$id = (string) (getenv('JAS_NODE_ID') ?: gethostname());
$endpoint = (string) (getenv('JAS_NODE_ENDPOINT') ?: 'tcp://127.0.0.1:9100');
$identity = NodeIdentity::loadOrCreate($root . '/runtime/cluster/identity', $id, $endpoint, ['*']);
$registry = new NodeRegistry($root . '/runtime/cluster/registry');
$registry->heartbeat($identity, ['pid' => getmypid(), 'role' => 'sync']);
$coordinator = new ClusterCoordinator(
    $identity,
    $registry,
    new LeaderElector($registry),
    new ReplicatedQueueLog($root . '/runtime/cluster/replication', $id)
);
$router = new ClusterMessageRouter($identity, $registry, $coordinator);
$tls = [
    'enabled' => filter_var(getenv('JAS_TLS_ENABLED') ?: '0', FILTER_VALIDATE_BOOL),
    'verify_peer' => filter_var(getenv('JAS_TLS_VERIFY_PEER') ?: '1', FILTER_VALIDATE_BOOL),
    'verify_peer_name' => filter_var(getenv('JAS_TLS_VERIFY_PEER_NAME') ?: '1', FILTER_VALIDATE_BOOL),
    'allow_self_signed' => filter_var(getenv('JAS_TLS_ALLOW_SELF_SIGNED') ?: '0', FILTER_VALIDATE_BOOL),
    'ca' => getenv('JAS_TLS_CA') ?: '',
    'peer_name' => getenv('JAS_TLS_PEER_NAME') ?: '',
];
$daemon = new ClusterSyncDaemon(
    $identity,
    $registry,
    $router,
    new TcpClusterClient(new FrameProtocol(), $tls),
    new SyncCursorStore($root . '/runtime/cluster/sync'),
    array_map('trim', explode(',', getenv('JAS_SYNC_STREAMS') ?: 'queue,objects,wal')),
    max(1, (int) (getenv('JAS_SYNC_INTERVAL') ?: 5))
);
$daemon->run(max(0, (int) ($argv[1] ?? 0)));
