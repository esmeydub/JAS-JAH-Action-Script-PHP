<?php

declare(strict_types=1);
require_once __DIR__ . '/support.php';
$boot=require dirname(__DIR__).'/app/bootstrap.php';$root=$boot['root'];$tmp=$root.'/runtime/test-enterprise-'.bin2hex(random_bytes(4));mkdir($tmp,0700,true);
use Jah\JAS\Cluster\{NodeIdentity,NodeRegistry,TermLeaderElector};use Jah\JAS\Consensus\{FencingTokenStore,QuorumCoordinator};use Jah\JAS\Balance\NodeLoadBalancer;use Jah\JAS\Sharding\{ShardMap,ShardReplicator};use Jah\JAS\Replication\ReplicatedQueueLog;use Jah\JAS\Snapshot\DistributedSnapshotManager;use Jah\JAS\Telemetry\MetricsRegistry;use Jah\JAS\Observability\AggregatedMetrics;use Jah\JAS\Transport\FrameProtocol;
function ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);echo "PASS {$m}\n";}
try{
 $reg=new NodeRegistry($tmp.'/registry',120);$nodes=[];foreach(['a','b','c'] as $i=>$id){$n=NodeIdentity::loadOrCreate($tmp.'/ids','node-'.$id,'tcp://127.0.0.1:'.(9201+$i),['storage.*','metrics.read']);$reg->heartbeat($n,['cpu_percent'=>$i*20,'active_jobs'=>$i,'latency_ms'=>2+$i]);$nodes[$n->id]=$n;}
 $e=new TermLeaderElector($tmp.'/election',$reg);$term=$e->elect();ok($term['leader_id']==='node-a'&&$term['term']===1,'term leader elected');
 $f=new FencingTokenStore($tmp.'/fence');$q=new QuorumCoordinator($reg,$f);$r=$q->commit('node-a',1,'op-1',['x'=>1],fn($node,$msg)=>['accepted'=>true],fn($payload,$fence)=>['stored'=>$payload,'token'=>$fence['token']]);ok($r['acks']===3&&$r['required']===2,'write quorum reached');
 $stale=false;try{$f->assertValid('node-a',1,$r['fencing']['token']-1);}catch(Throwable){$stale=true;}ok($stale,'stale fencing rejected');
 $lb=new NodeLoadBalancer($reg);ok($lb->select('storage.write')['id']==='node-a','least loaded node selected');
 $map=new ShardMap($reg,2);$shard=$map->shardFor('objects','obj-1',16);ok(count($map->owners('objects',$shard))===2,'replica owners selected');
 $log=new ReplicatedQueueLog($tmp.'/replication','node-a');$sr=new ShardReplicator($tmp.'/shards',$log);$sr->put('objects',$shard,'obj-1',['value'=>42],$r['fencing']);ok(($sr->latest('objects',$shard)['obj-1']['value']??null)===42,'shard mutation persisted');ok($log->verify('shard:objects:'.$shard),'shard replication chain valid');
 file_put_contents($tmp.'/queue.journal','queue');file_put_contents($tmp.'/wal.journal','wal');$snap=new DistributedSnapshotManager($tmp.'/snapshots');$m=$snap->create('snap-1',['queue.journal'=>$tmp.'/queue.journal','wal.journal'=>$tmp.'/wal.journal'],['term'=>1]);ok(count($m['files'])===2&&$snap->verify('snap-1'),'distributed snapshot verified');
 $m1=new MetricsRegistry($tmp.'/m1');$m2=new MetricsRegistry($tmp.'/m2');$m1->increment('jobs',2);$m2->increment('jobs',3);$m1->gauge('workers',1);$m2->gauge('workers',2);$m1->observe('latency',10);$m2->observe('latency',20);$agg=AggregatedMetrics::combine(['a'=>$m1->snapshot(),'b'=>$m2->snapshot()]);ok($agg['counters']['jobs']===5&&$agg['gauges']['workers']===3&&$agg['timings']['latency']['avg_ms']===15.0,'metrics aggregated');
 $fp=new FrameProtocol(4096);$payload=random_bytes(512);$stream=fopen('php://temp','w+b');$fp->write($stream,$payload);rewind($stream);ok($fp->read($stream)===$payload,'binary framing roundtrip');fclose($stream);
 echo "JAS ENTERPRISE: PASS\n";
}finally{jas_test_remove_tree($tmp);}
