#!/usr/bin/env php
<?php

declare(strict_types=1);
$boot=require __DIR__.'/app/bootstrap.php';$root=$boot['root'];
use Jah\JAS\Cluster\{NodeIdentity,NodeRegistry,LeaderElector,ClusterCoordinator,ClusterMessageRouter};
use Jah\JAS\Replication\ReplicatedQueueLog;use Jah\JAS\Consensus\QuorumPrepareStore;use Jah\JAS\Telemetry\MetricsRegistry;use Jah\JAS\Transport\FrameProtocol;use Jah\JAS\Server\PersistentTcpServer;
$id=getenv('JAS_NODE_ID')?:gethostname();$endpoint=getenv('JAS_NODE_ENDPOINT')?:'tcp://127.0.0.1:9100';
$identity=NodeIdentity::loadOrCreate($root.'/runtime/cluster/identity',$id,$endpoint,['*']);$registry=new NodeRegistry($root.'/runtime/cluster/registry');$registry->heartbeat($identity,['pid'=>getmypid(),'cpu_percent'=>0,'active_jobs'=>0]);$coord=new ClusterCoordinator($identity,$registry,new LeaderElector($registry),new ReplicatedQueueLog($root.'/runtime/cluster/replication',$id));$router=new ClusterMessageRouter($identity,$registry,$coord,new QuorumPrepareStore($root.'/runtime/cluster/quorum'),new MetricsRegistry($root.'/runtime/telemetry'));
$tls=['enabled'=>filter_var(getenv('JAS_TLS_ENABLED')?:'0',FILTER_VALIDATE_BOOL),'cert'=>getenv('JAS_TLS_CERT')?:'','key'=>getenv('JAS_TLS_KEY')?:'','passphrase'=>getenv('JAS_TLS_PASSPHRASE')?:'','verify_peer'=>filter_var(getenv('JAS_TLS_VERIFY_PEER')?:'0',FILTER_VALIDATE_BOOL),'allow_self_signed'=>filter_var(getenv('JAS_TLS_ALLOW_SELF_SIGNED')?:'0',FILTER_VALIDATE_BOOL)];
$server=new PersistentTcpServer($endpoint,new FrameProtocol(),fn(string $payload):string=>$router->handle($payload),$tls);echo "JAS cluster server {$id} on {$endpoint}\n";$server->run();
