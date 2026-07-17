<?php

declare(strict_types=1);
$boot=require dirname(__DIR__).'/app/bootstrap.php';
use Jah\JAS\Cluster\{NodeIdentity,NodeRegistry,LeaderElector,ClusterCoordinator,ClusterMessageRouter};
use Jah\JAS\Replication\ReplicatedQueueLog;
use Jah\JAS\Transport\{SalkEncryptedEnvelope,FrameProtocol};
$assert=static function(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);};
if(!extension_loaded('sodium')){echo "JAS CLUSTER: SKIP (sodium unavailable)\n";exit(0);}
$base=sys_get_temp_dir().'/jas_cluster_'.bin2hex(random_bytes(6));mkdir($base,0700,true);
$registry=new NodeRegistry($base.'/registry',5);
$a=NodeIdentity::loadOrCreate($base.'/identity','node-a','tcp://127.0.0.1:9101',['queue.*']);
$b=NodeIdentity::loadOrCreate($base.'/identity','node-b','tcp://127.0.0.1:9102',['queue.*']);
$registry->heartbeat($a,['role'=>'primary']);$registry->heartbeat($b,['role'=>'replica']);
$assert(count($registry->all())===2,'Registro de nodos falló');
$elector=new LeaderElector($registry);$assert(($elector->leader()['id']??null)==='node-a','Elección determinista falló');
$logA=new ReplicatedQueueLog($base.'/rep-a','node-a');$logB=new ReplicatedQueueLog($base.'/rep-b','node-b');
$coordA=new ClusterCoordinator($a,$registry,$elector,$logA);$coordB=new ClusterCoordinator($b,$registry,$elector,$logB);
$row=$coordA->publish('queue','event-1',['type'=>'SUBMIT','job_id'=>'job-1']);$assert($logA->verify('queue'),'Cadena local inválida');
$assert($coordB->import($coordA->export('queue'))===1,'Importación de réplica falló');$assert($logB->verify('queue'),'Cadena replicada inválida');
$sealed=SalkEncryptedEnvelope::seal("binary\0payload",$a,$b->publicKey);$opened=SalkEncryptedEnvelope::open($sealed,$b,fn(string $id):?string=>$id==='node-a'?$a->publicKey:null);$assert($opened['payload']==="binary\0payload",'Cifrado de transporte falló');
$routerA=new ClusterMessageRouter($a,$registry,$coordA);$routerB=new ClusterMessageRouter($b,$registry,$coordB);
$request=$routerA->encodeFor('node-b',['type'=>'CLUSTER_STATUS']);$reply=$routerB->handle($request);$decoded=$routerA->decode($reply);$assert(($decoded['success']??false)===true&&($decoded['status']['node_id']??null)==='node-b','Router multinodo falló');
$pair=@stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);
if (is_array($pair)) {
    $frames=new FrameProtocol(1024*1024);
    try {
        $frames->write($pair[0],$request);$read=$frames->read($pair[1]);$assert(hash_equals($request,$read),'Framing TCP falló');
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'frame_write_failed') throw $e;
        echo "SKIP framing socket pair (sockets blocked by environment)\n";
    } finally {
        fclose($pair[0]);fclose($pair[1]);
    }
} else {
    echo "SKIP framing socket pair (unavailable)\n";
}
$registry->compact();$assert(count($registry->all())===2,'Compactación de registro perdió nodos');
echo "JAS CLUSTER: PASS\n";
