<?php

declare(strict_types=1);

namespace Jah\JAS\Observability;

use Jah\DataCore\PhpSerializer;
use Jah\JAS\Cluster\NodeIdentity;
use RuntimeException;

/** Signed, append-only evidence for the real seven-day operational gate. */
final class SustainedOperationEvidence
{
    public const MINIMUM_DURATION_SECONDS = 604_800;

    private readonly string $manifestFile;
    private readonly string $samplesFile;
    private readonly string $lockFile;

    public function __construct(
        string $directory,
        private readonly NodeIdentity $identity,
        private readonly int $durationSeconds = self::MINIMUM_DURATION_SECONDS,
        private readonly int $sampleIntervalSeconds = 60,
        private readonly int $maximumGapSeconds = 300,
    ) {
        if ($durationSeconds < self::MINIMUM_DURATION_SECONDS || $durationSeconds > 2_678_400) {
            throw new RuntimeException('operations_evidence_duration_invalid');
        }
        if ($sampleIntervalSeconds < 10 || $sampleIntervalSeconds > 3_600
            || $maximumGapSeconds < $sampleIntervalSeconds || $maximumGapSeconds > 3_600) {
            throw new RuntimeException('operations_evidence_interval_invalid');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('operations_evidence_directory_failed');
        }
        $root = rtrim($directory, '/');
        $this->manifestFile = $root . '/campaign.jahl';
        $this->samplesFile = $root . '/samples.jahl';
        $this->lockFile = $root . '/campaign.lock';
    }

    public function start(?int $now = null): array
    {
        $now ??= time();
        return $this->locked(function () use ($now): array {
            if (is_file($this->manifestFile)) return $this->readManifest();
            $manifest = [
                'format' => 'JAS-OPERATIONS-EVIDENCE-1',
                'campaign_id' => bin2hex(random_bytes(16)),
                'node_id' => $this->identity->id,
                'node_public_key' => base64_encode($this->identity->publicKey),
                'started_at' => $now,
                'target_duration_seconds' => $this->durationSeconds,
                'sample_interval_seconds' => $this->sampleIntervalSeconds,
                'maximum_gap_seconds' => $this->maximumGapSeconds,
            ];
            $manifest['signature'] = base64_encode($this->identity->sign(PhpSerializer::encode($manifest)));
            $this->atomicWrite($this->manifestFile, PhpSerializer::encode($manifest) . "\n");
            return $manifest;
        });
    }

    /**
     * @param array{operations:int,accepted:int,rejected:int,integrity_valid:bool,recovery_valid:bool,readiness:bool,queue_bounded:bool,disk_level:string} $observation
     * @return array{recorded:bool,due:bool,sequence:int,sample_ok:bool}
     */
    public function record(array $observation, ?int $now = null): array
    {
        $now ??= time();
        $this->validateObservation($observation);
        $this->start($now);
        return $this->locked(function () use ($observation, $now): array {
            $manifest = $this->readManifest();
            $samples = $this->readAndVerifySamples($manifest);
            $last = $samples === [] ? null : $samples[array_key_last($samples)];
            if ($last !== null && $now < (int) $last['sampled_at']) {
                throw new RuntimeException('operations_evidence_clock_rollback');
            }
            if ($last !== null && $now < (int) $last['sampled_at'] + $this->sampleIntervalSeconds) {
                return [
                    'recorded' => false, 'due' => false,
                    'sequence' => (int) $last['sequence'], 'sample_ok' => (bool) $last['sample_ok'],
                ];
            }
            $previousAt = $last === null ? (int) $manifest['started_at'] : (int) $last['sampled_at'];
            $gap = $now - $previousAt;
            $sampleOk = $gap >= 0 && $gap <= $this->maximumGapSeconds
                && $observation['operations'] === $observation['accepted'] + $observation['rejected']
                && $observation['integrity_valid'] && $observation['recovery_valid']
                && $observation['readiness'] && $observation['queue_bounded']
                && $observation['disk_level'] !== DiskPressureGuard::EMERGENCY;
            $entry = [
                'campaign_id' => $manifest['campaign_id'],
                'sequence' => count($samples) + 1,
                'sampled_at' => $now,
                'gap_seconds' => $gap,
                'observation' => $observation,
                'sample_ok' => $sampleOk,
                'previous_hash' => $last['hash'] ?? str_repeat('0', 64),
            ];
            $entry['hash'] = hash('sha256', PhpSerializer::encode($entry));
            $entry['signature'] = base64_encode($this->identity->sign((string) $entry['hash']));
            $this->append(PhpSerializer::encode($entry) . "\n");
            return ['recorded' => true, 'due' => true, 'sequence' => $entry['sequence'], 'sample_ok' => $sampleOk];
        });
    }

    public function status(?int $now = null): array
    {
        $now ??= time();
        return $this->locked(function () use ($now): array {
            if (!is_file($this->manifestFile)) {
                return ['started' => false, 'complete' => false, 'verified' => true, 'remaining_seconds' => $this->durationSeconds];
            }
            $manifest = $this->readManifest();
            $samples = $this->readAndVerifySamples($manifest);
            $started = (int) $manifest['started_at'];
            $targetAt = $started + (int) $manifest['target_duration_seconds'];
            $lastAt = $samples === [] ? $started : (int) $samples[array_key_last($samples)]['sampled_at'];
            $allOk = $samples !== [];
            foreach ($samples as $sample) $allOk = $allOk && ($sample['sample_ok'] ?? false) === true;
            $complete = $lastAt >= $targetAt && $allOk;
            return [
                'started' => true,
                'complete' => $complete,
                'verified' => true,
                'campaign_id' => $manifest['campaign_id'],
                'node_id' => $manifest['node_id'],
                'started_at' => $started,
                'target_at' => $targetAt,
                'last_sample_at' => $samples === [] ? null : $lastAt,
                'sample_count' => count($samples),
                'valid_sample_count' => count(array_filter($samples, static fn(array $sample): bool => ($sample['sample_ok'] ?? false) === true)),
                'remaining_seconds' => max(0, $targetAt - max($started, $lastAt)),
                'currently_overdue' => !$complete && $samples !== [] && $now - $lastAt > (int) $manifest['maximum_gap_seconds'],
            ];
        });
    }

    private function readManifest(): array
    {
        $manifest = PhpSerializer::decode(trim((string) @file_get_contents($this->manifestFile)));
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== 'JAS-OPERATIONS-EVIDENCE-1'
            || ($manifest['node_id'] ?? null) !== $this->identity->id
            || !is_string($manifest['signature'] ?? null)) {
            throw new RuntimeException('operations_evidence_manifest_corrupt');
        }
        $signature = base64_decode($manifest['signature'], true);
        $public = base64_decode((string) ($manifest['node_public_key'] ?? ''), true);
        unset($manifest['signature']);
        if (!is_string($signature) || !is_string($public) || !hash_equals($public, $this->identity->publicKey)
            || !NodeIdentity::verify(PhpSerializer::encode($manifest), $signature, $public)) {
            throw new RuntimeException('operations_evidence_manifest_signature_invalid');
        }
        $manifest['signature'] = base64_encode($signature);
        return $manifest;
    }

    /** @return list<array> */
    private function readAndVerifySamples(array $manifest): array
    {
        if (!is_file($this->samplesFile)) return [];
        $samples = [];
        $previous = str_repeat('0', 64);
        foreach (file($this->samplesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = PhpSerializer::decode($line);
            if (!is_array($entry) || ($entry['campaign_id'] ?? null) !== $manifest['campaign_id']
                || ($entry['sequence'] ?? null) !== count($samples) + 1 || ($entry['previous_hash'] ?? null) !== $previous) {
                throw new RuntimeException('operations_evidence_chain_corrupt');
            }
            $hash = $entry['hash'] ?? null;
            $signature = base64_decode((string) ($entry['signature'] ?? ''), true);
            $unsigned = $entry;
            unset($unsigned['hash'], $unsigned['signature']);
            if (!is_string($hash) || !hash_equals($hash, hash('sha256', PhpSerializer::encode($unsigned)))
                || !is_string($signature) || !NodeIdentity::verify($hash, $signature, $this->identity->publicKey)) {
                throw new RuntimeException('operations_evidence_sample_signature_invalid');
            }
            $previous = $hash;
            $samples[] = $entry;
        }
        return $samples;
    }

    private function validateObservation(array $observation): void
    {
        foreach (['operations', 'accepted', 'rejected'] as $field) {
            if (!isset($observation[$field]) || !is_int($observation[$field]) || $observation[$field] < 0 || $observation[$field] > 1_000_000) {
                throw new RuntimeException('operations_evidence_observation_invalid');
            }
        }
        if ($observation['operations'] < 1) throw new RuntimeException('operations_evidence_observation_invalid');
        foreach (['integrity_valid', 'recovery_valid', 'readiness', 'queue_bounded'] as $field) {
            if (!isset($observation[$field]) || !is_bool($observation[$field])) throw new RuntimeException('operations_evidence_observation_invalid');
        }
        if (!in_array($observation['disk_level'] ?? null, [
            DiskPressureGuard::NORMAL, DiskPressureGuard::WARNING,
            DiskPressureGuard::CRITICAL, DiskPressureGuard::EMERGENCY,
        ], true)) throw new RuntimeException('operations_evidence_observation_invalid');
    }

    private function append(string $payload): void
    {
        $handle = fopen($this->samplesFile, 'ab');
        if ($handle === false) throw new RuntimeException('operations_evidence_append_failed');
        try {
            if (fwrite($handle, $payload) !== strlen($payload) || !fflush($handle)
                || (function_exists('fsync') && !@fsync($handle))) throw new RuntimeException('operations_evidence_append_failed');
        } finally { fclose($handle); }
        @chmod($this->samplesFile, 0600);
    }

    private function atomicWrite(string $path, string $payload): void
    {
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(4));
        $handle = fopen($temporary, 'xb');
        if ($handle === false) throw new RuntimeException('operations_evidence_write_failed');
        try {
            if (fwrite($handle, $payload) !== strlen($payload) || !fflush($handle)
                || (function_exists('fsync') && !@fsync($handle))) throw new RuntimeException('operations_evidence_write_failed');
        } finally { fclose($handle); }
        if (!rename($temporary, $path)) { @unlink($temporary); throw new RuntimeException('operations_evidence_publish_failed'); }
        @chmod($path, 0600);
    }

    private function locked(callable $operation): mixed
    {
        $handle = fopen($this->lockFile, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('operations_evidence_lock_failed');
        try { return $operation(); } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
