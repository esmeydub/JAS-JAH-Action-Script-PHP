<?php

declare(strict_types=1);

$boot = require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Cluster\{NodeIdentity, NodeRegistry, LeaderElector, ClusterCoordinator, ClusterMessageRouter};
use Jah\JAS\Replication\ReplicatedQueueLog;
use Jah\JAS\Sync\SyncCursorStore;
use Jah\JAS\Server\{PersistentTcpServer, TcpClusterClient};
use Jah\JAS\Transport\FrameProtocol;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS {$message}\n";
};

$base = sys_get_temp_dir() . '/jas_regressions_' . bin2hex(random_bytes(6));
mkdir($base, 0700, true);

try {
    $logA = new ReplicatedQueueLog($base . '/a', 'node-a');
    $logB = new ReplicatedQueueLog($base . '/b', 'node-b');
    $merged = new ReplicatedQueueLog($base . '/merged', 'node-c');

    $logA->append('queue', 'a-1', ['value' => 1]);
    $logA->append('queue', 'a-2', ['value' => 2]);
    $logB->append('queue', 'b-1', ['value' => 3]);
    $logB->append('queue', 'b-2', ['value' => 4]);

    $assert($merged->import($logA->events('queue')) === 2, 'imports first origin chain');
    $assert($merged->import($logB->events('queue')) === 2, 'imports second origin chain');
    $assert($merged->verify('queue'), 'verifies merged multi-origin chains');
    $assert($merged->lastSequence('queue', 'node-a') === 2, 'tracks origin-specific sequence');
    $assert($merged->lastSequence('queue', 'node-b') === 2, 'tracks second origin sequence');

    $cursor = new SyncCursorStore($base . '/cursors');
    $cursor->advance('node-a', 'queue', 2);
    $cursor->advance('node-a', 'queue', 1);
    $assert($cursor->get('node-a', 'queue') === 2, 'sync cursor never moves backwards');

    if (!extension_loaded('pcntl') || !tcpBindingAvailable()) {
        echo "SKIP persistent TCP roundtrip (TCP binding or PCNTL unavailable)\n";
    } else {
        $frames = new FrameProtocol(1024 * 1024);
        $port = random_int(20000, 45000);
        $endpoint = 'tcp://127.0.0.1:' . $port;
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('fork_failed');
        if ($pid === 0) {
            $server = new PersistentTcpServer($endpoint, $frames, static fn(string $payload): string => 'echo:' . $payload);
            $server->run(1);
            exit(0);
        }
        usleep(200000);
        $client = new TcpClusterClient($frames);
        $assert($client->request($endpoint, "binary\0data") === "echo:binary\0data", 'persistent TCP server roundtrip');
        pcntl_waitpid($pid, $status);
        $assert(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'persistent TCP server exits cleanly');
    }

    echo "JAS REGRESSIONS: PASS\n";
} finally {
    removeTree($base);
}

function tcpBindingAvailable(): bool
{
    $errno = 0;
    $error = '';
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!is_resource($socket)) return false;
    fclose($socket);
    return true;
}

function removeTree(string $path): void
{
    if (!is_dir($path)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}
