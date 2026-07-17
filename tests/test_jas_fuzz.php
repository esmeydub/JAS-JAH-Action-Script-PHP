<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Protocol\JasBinaryCodec;
use Jah\JAS\Protocol\JasPacket;
use Jah\JAS\Security\SalkPacketGuard;
use Jah\JAS\Type\TypeRegistry;

mt_srand(0x4A4153);
$codec = new JasBinaryCodec(new SalkPacketGuard(str_repeat('fuzz-key-', 4)));
$roundTrips = 0; $rejections = 0;
for ($index = 0; $index < 500; $index++) {
    $payloadLength = mt_rand(0, 4096);
    $payload = random_bytes($payloadLength);
    $packet = new JasPacket(
        mt_rand(0, 65535), mt_rand(0, 65535),
        'req-' . $index, 'object-' . mt_rand(1, 100), $payload, mt_rand(1, 2_000_000_000)
    );
    $encoded = $codec->encode($packet);
    $decoded = $codec->decode($encoded);
    if ($decoded->payload !== $payload || $decoded->opcode !== $packet->opcode || $decoded->requestId !== $packet->requestId) {
        throw new RuntimeException('jasb_fuzz_roundtrip_failed');
    }
    $roundTrips++;
    $position = mt_rand(0, strlen($encoded) - 1);
    $encoded[$position] = chr(ord($encoded[$position]) ^ (1 << mt_rand(0, 7)));
    try { $codec->decode($encoded); }
    catch (InvalidArgumentException) { $rejections++; continue; }
    throw new RuntimeException('jasb_fuzz_corruption_accepted');
}

$types = (new TypeRegistry())->define('FuzzRecord', [
    'id' => 'identifier', 'count' => 'non-negative-int', 'name?' => 'non-empty-string',
]);
for ($index = 0; $index < 1_000; $index++) {
    $valid = ['id' => 'REC-' . $index, 'count' => $index];
    if (!$types->validate('FuzzRecord', $valid)) throw new RuntimeException('type_fuzz_valid_rejected');
    $unknown = $valid + ['unexpected' => random_bytes(4)];
    if ($types->validate('FuzzRecord', $unknown)) throw new RuntimeException('type_fuzz_unknown_accepted');
    $invalid = ['id' => 'bad id ' . $index, 'count' => -1];
    if ($types->validate('FuzzRecord', $invalid)) throw new RuntimeException('type_fuzz_invalid_accepted');
}

echo "JAS FUZZ: PASS ({$roundTrips} roundtrips, {$rejections} corruptions rejected)\n";
