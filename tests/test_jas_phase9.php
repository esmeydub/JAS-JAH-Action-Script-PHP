<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/support.php';

use Jah\DataCore\DataCoreTurbo;
use Jah\JAS\Security\KeyRing;
use Jah\JAS\Transport\FrameProtocol;
use Jah\JAS\Type\TypeRegistry;
use Jah\JAS\Web\Form;

if (!extension_loaded('pcntl') || !extension_loaded('sodium')) {
    fwrite(STDERR, "JAS PHASE 9 requires PCNTL and Sodium\n");
    exit(2);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$base = sys_get_temp_dir() . '/jas_phase9_' . bin2hex(random_bytes(6));
mkdir($base, 0700, true);

try {
    // Property and multiprocess test: four independent processes append to the
    // same DataCore collection. Every acknowledged record must survive reload.
    $workers = 4;
    $recordsPerWorker = 125;
    $children = [];
    for ($worker = 0; $worker < $workers; $worker++) {
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('phase9_fork_failed');
        if ($pid === 0) {
            $store = new DataCoreTurbo($base . '/datacore', 1);
            for ($record = 0; $record < $recordsPerWorker; $record++) {
                $id = sprintf('W%02d-R%04d', $worker, $record);
                $payload = random_bytes(($record % 257) + 1);
                $store->insert('phase9', [
                    'id' => $id,
                    'worker' => $worker,
                    'sequence' => $record,
                    'payload_hash' => hash('sha256', $payload),
                ]);
            }
            $store->flush();
            exit(0);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        $assert(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'phase9_worker_failed');
    }
    $reloaded = new DataCoreTurbo($base . '/datacore', 1);
    for ($worker = 0; $worker < $workers; $worker++) {
        for ($record = 0; $record < $recordsPerWorker; $record++) {
            $id = sprintf('W%02d-R%04d', $worker, $record);
            $found = $reloaded->find('phase9', $id);
            $assert(($found['worker'] ?? null) === $worker && ($found['sequence'] ?? null) === $record, 'phase9_datacore_record_lost');
        }
    }

    // Forced shutdown: the child is terminated while continuously flushing.
    // Recovery may lose an unacknowledged tail, but every indexed record must
    // decode completely and no fabricated record may appear.
    $pid = pcntl_fork();
    if ($pid === -1) throw new RuntimeException('phase9_crash_fork_failed');
    if ($pid === 0) {
        $store = new DataCoreTurbo($base . '/crash', 1);
        for ($record = 0; $record < 10_000; $record++) {
            $store->insert('crash', ['id' => 'CRASH-' . $record, 'sequence' => $record, 'proof' => hash('sha256', (string) $record)]);
        }
        exit(0);
    }
    usleep(75_000);
    posix_kill($pid, SIGKILL);
    pcntl_waitpid($pid, $status);
    $assert(pcntl_wifsignaled($status), 'phase9_forced_shutdown_not_observed');
    $recovered = new DataCoreTurbo($base . '/crash', 1);
    $survivors = 0;
    for ($record = 0; $record < 10_000; $record++) {
        $found = $recovered->find('crash', 'CRASH-' . $record);
        if ($found === null) continue;
        $assert(($found['sequence'] ?? null) === $record, 'phase9_crash_record_mixed');
        $assert(hash_equals(hash('sha256', (string) $record), (string) ($found['proof'] ?? '')), 'phase9_crash_record_corrupt');
        $survivors++;
    }
    $assert($survivors > 0, 'phase9_crash_recovered_nothing');

    // Transport properties: fragmented construction succeeds; simulated packet
    // loss (truncation) and oversized frames fail closed. Real socket behavior
    // remains covered by the cluster/LSP suites where the runner permits it.
    $frames = new FrameProtocol(65_536);
    $payload = random_bytes(32_768);
    $frame = $frames->encode($payload);
    $fragmented = fopen('php://temp', 'w+b');
    for ($offset = 0; $offset < strlen($frame); $offset += 257) {
        $written = fwrite($fragmented, substr($frame, $offset, 257));
        $assert(is_int($written) && $written > 0, 'phase9_fragment_write_failed');
    }
    rewind($fragmented);
    $assert(hash_equals($payload, $frames->read($fragmented)), 'phase9_fragmented_frame_mismatch');
    fclose($fragmented);

    $truncated = fopen('php://temp', 'w+b');
    fwrite($truncated, pack('N', 100) . random_bytes(12));
    rewind($truncated);
    try {
        $frames->read($truncated);
        throw new RuntimeException('phase9_truncated_frame_accepted');
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'frame_truncated', 'phase9_truncated_frame_wrong_error');
    }
    fclose($truncated);
    try {
        $frames->encode(random_bytes(65_537));
        throw new RuntimeException('phase9_oversized_frame_accepted');
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'frame_too_large', 'phase9_oversized_frame_wrong_error');
    }

    // Key rotation under load: old and new ciphertexts remain readable only
    // while their key is explicitly retained; unknown keys fail closed.
    $keys = ['2026-a' => random_bytes(32), '2026-b' => random_bytes(32)];
    $oldRing = new KeyRing($keys, '2026-a');
    $newRing = new KeyRing($keys, '2026-b');
    $ciphertexts = [];
    for ($index = 0; $index < 1_000; $index++) {
        $plain = 'record:' . $index . ':' . bin2hex(random_bytes(16));
        $sealed = ($index < 500 ? $oldRing : $newRing)->encrypt('phase9.rotation', $plain);
        $ciphertexts[] = [$plain, $sealed];
    }
    foreach ($ciphertexts as [$plain, $sealed]) {
        $assert($newRing->decrypt('phase9.rotation', $sealed['key_id'], $sealed['ciphertext']) === $plain, 'phase9_rotation_decryption_failed');
    }
    $retiredRing = new KeyRing(['2026-b' => $keys['2026-b']], '2026-b');
    try {
        $retiredRing->decrypt('phase9.rotation', '2026-a', $ciphertexts[0][1]['ciphertext']);
        throw new RuntimeException('phase9_retired_key_accepted');
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'keyring_key_not_found', 'phase9_retired_key_wrong_error');
    }

    // Form fuzzing: unknown fields, CSRF attacks and HTML payloads are rejected
    // or escaped; no submitted value becomes executable markup.
    $types = (new TypeRegistry())->define('Phase9Form', ['id' => 'identifier', 'name' => 'non-empty-string']);
    $token = bin2hex(random_bytes(32));
    for ($index = 0; $index < 500; $index++) {
        $attack = '<script data-i="' . $index . '">alert(1)</script>';
        $form = new Form($types, 'Phase9Form', '/phase9', $token);
        $result = $form->submit(['_csrf' => random_bytes(32), 'id' => 'REC-' . $index, 'name' => $attack, 'admin' => '1']);
        $assert($result['valid'] === false, 'phase9_form_attack_accepted');
        $html = $form->render()->value();
        $assert(!str_contains($html, '<script'), 'phase9_form_xss_rendered');
        $assert(str_contains($html, '&lt;script'), 'phase9_form_value_not_escaped');
    }

    echo "JAS PHASE 9 INTERNAL VERIFICATION: PASS ({$workers} processes, {$survivors} crash survivors, 1000 rotations, 500 form attacks)\n";
} finally {
    jas_test_remove_tree($base);
}
