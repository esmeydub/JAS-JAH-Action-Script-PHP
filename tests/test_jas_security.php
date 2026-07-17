<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Cluster\NodeIdentity;
use Jah\JAS\Protocol\JasBinaryCodec;
use Jah\JAS\Protocol\JasPacket;
use Jah\JAS\Security\ReplayGuard;
use Jah\JAS\Security\SalkPacketGuard;
use Jah\JAS\Security\DualControlStore;
use Jah\JAS\Transport\SalkEncryptedEnvelope;

$assertThrows = static function (callable $operation, string $expected): void {
    try {
        $operation();
    } catch (Throwable $e) {
        if ($e->getMessage() === $expected) {
            echo "PASS rejects {$expected}\n";
            return;
        }
        throw $e;
    }
    throw new RuntimeException("Expected {$expected}");
};

if (!extension_loaded('sodium')) {
    echo "JAS SECURITY: SKIP (sodium unavailable)\n";
    exit(0);
}

$directory = sys_get_temp_dir() . '/jas_security_' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);

$alice = NodeIdentity::loadOrCreate($directory, 'node-alice', 'tcp://127.0.0.1:9101');
$bob = NodeIdentity::loadOrCreate($directory, 'node-bob', 'tcp://127.0.0.1:9102');
$resolver = static fn(string $id): ?string => $id === $alice->id ? $alice->publicKey : null;
$envelope = SalkEncryptedEnvelope::seal('secure-payload', $alice, $bob->publicKey);
$opened = SalkEncryptedEnvelope::open($envelope, $bob, $resolver);
if ($opened['payload'] !== 'secure-payload') throw new RuntimeException('secure_roundtrip_failed');
echo "PASS encrypted envelope roundtrip\n";

$tampered = $envelope;
$tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 1);
$assertThrows(fn() => SalkEncryptedEnvelope::open($tampered, $bob, $resolver), 'envelope_signature_invalid');
$assertThrows(fn() => SalkEncryptedEnvelope::open(substr($envelope, 0, 20), $bob, $resolver), 'envelope_invalid');
$assertThrows(fn() => SalkEncryptedEnvelope::seal('x', $alice, 'bad-key'), 'recipient_public_key_invalid');

$corruptDirectory = $directory . '/corrupt';
mkdir($corruptDirectory, 0700, true);
file_put_contents($corruptDirectory . '/node-corrupt.identity', serialize(['public' => '!', 'secret' => '!']));
$assertThrows(
    fn() => NodeIdentity::loadOrCreate($corruptDirectory, 'node-corrupt', 'tcp://127.0.0.1:9199'),
    'node_identity_corrupt'
);

$packetGuard = new SalkPacketGuard(str_repeat('security-key-', 3));
$codec = new JasBinaryCodec($packetGuard);
$packet = new JasPacket(100, 0, 'security-request', 'object-1', 'binary-payload', time());
$encoded = $codec->encode($packet);
$altered = $encoded;
$altered[20] = chr(ord($altered[20]) ^ 1);
$assertThrows(fn() => $codec->decode($altered), 'Firma SALK inválida');
$assertThrows(fn() => $codec->decode($encoded, -1), 'Límite de payload JAS inválido');

$replay = new ReplayGuard($directory . '/replay', 60);
$replay->assertFresh('once-only', time());
$assertThrows(fn() => $replay->assertFresh('once-only', time()), 'Replay SALK detectado');
$assertThrows(fn() => $replay->assertFresh('expired', time() - 61), 'Paquete JAS vencido o con reloj inválido');
$assertThrows(fn() => new ReplayGuard($directory . '/bad-replay', 0), 'replay_ttl_invalid');

$dual = new DualControlStore($directory . '/dual-control');
$approval = $dual->request('expediente.eliminar', 'USER-REQUESTER', 'critical-1', hash('sha256', 'EXP-1'));
$assertThrows(fn() => $dual->approve($approval, 'USER-REQUESTER'), 'dual_control_same_actor_forbidden');
$dual->approve($approval, 'USER-APPROVER');
$assertThrows(fn() => $dual->consume($approval, 'expediente.eliminar', 'critical-1', hash('sha256', 'EXP-2')), 'dual_control_context_mismatch');
$consumed = $dual->consume($approval, 'expediente.eliminar', 'critical-1', hash('sha256', 'EXP-1'));
if (($consumed['requester_id'] ?? null) !== 'USER-REQUESTER') throw new RuntimeException('dual_control_consume_failed');
$assertThrows(fn() => $dual->consume($approval, 'expediente.eliminar', 'critical-1', hash('sha256', 'EXP-1')), 'dual_control_not_approved');

echo "JAS SECURITY: PASS\n";
