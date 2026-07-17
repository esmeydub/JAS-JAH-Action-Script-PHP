<?php

declare(strict_types=1);

namespace Jah\JAS\Security;

use Jah\DataCore\PhpSerializer;
use RuntimeException;

final class DualControlStore
{
    private string $file;
    private string $lock;
    public function __construct(string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('dual_control_directory_failed');
        $this->file = rtrim($directory, '/') . '/approvals.jahl';
        $this->lock = rtrim($directory, '/') . '/approvals.lock';
    }

    public function request(string $action, string $requesterId, string $requestId, string $payloadFingerprint, int $ttlSeconds = 1800): string
    {
        if ($ttlSeconds < 60 || $ttlSeconds > 86_400) throw new RuntimeException('dual_control_ttl_invalid');
        $id = bin2hex(random_bytes(16));
        $this->append([
            'type' => 'REQUESTED',
            'id' => $id,
            'action' => $action,
            'requester_id' => $requesterId,
            'request_id' => $requestId,
            'payload_fingerprint' => $payloadFingerprint,
            'expires_at' => time() + $ttlSeconds,
            'at' => microtime(true),
        ]);
        return $id;
    }

    public function approve(string $approvalId, string $approverId): array
    {
        return $this->locked(function () use ($approvalId, $approverId): array {
            $state = $this->stateUnlocked()[$approvalId] ?? throw new RuntimeException('dual_control_not_found');
            if (($state['status'] ?? '') !== 'pending') throw new RuntimeException('dual_control_not_pending');
            if ((int) $state['expires_at'] < time()) throw new RuntimeException('dual_control_expired');
            if (hash_equals((string) $state['requester_id'], $approverId)) throw new RuntimeException('dual_control_same_actor_forbidden');
            $this->appendUnlocked(['type' => 'APPROVED', 'id' => $approvalId, 'approver_id' => $approverId, 'at' => microtime(true)]);
            return array_replace($state, ['status' => 'approved', 'approver_id' => $approverId]);
        });
    }

    public function consume(string $approvalId, string $action, string $requestId, string $payloadFingerprint): array
    {
        return $this->locked(function () use ($approvalId, $action, $requestId, $payloadFingerprint): array {
            $state = $this->stateUnlocked()[$approvalId] ?? throw new RuntimeException('dual_control_not_found');
            if (($state['status'] ?? '') !== 'approved') throw new RuntimeException('dual_control_not_approved');
            $sameContext = ($state['action'] ?? null) === $action
                && ($state['request_id'] ?? null) === $requestId
                && hash_equals((string) $state['payload_fingerprint'], $payloadFingerprint);
            if (!$sameContext) {
                throw new RuntimeException('dual_control_context_mismatch');
            }
            $this->appendUnlocked(['type' => 'CONSUMED', 'id' => $approvalId, 'at' => microtime(true)]);
            return $state;
        });
    }

    public function state(): array { return $this->locked(fn(): array => $this->stateUnlocked()); }

    private function stateUnlocked(): array
    {
        $state = [];
        if (!is_file($this->file)) return $state;
        foreach (file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = PhpSerializer::decode($line);
            if (!is_array($entry) || !isset($entry['id'], $entry['type'])) throw new RuntimeException('dual_control_corrupt');
            $id = (string) $entry['id'];
            if ($entry['type'] === 'REQUESTED') $state[$id] = $entry + ['status' => 'pending'];
            elseif ($entry['type'] === 'APPROVED' && isset($state[$id])) $state[$id] = $state[$id] + ['approver_id' => $entry['approver_id'], 'approved_at' => $entry['at']];
            elseif ($entry['type'] === 'CONSUMED' && isset($state[$id])) $state[$id]['consumed_at'] = $entry['at'];
            if ($entry['type'] === 'APPROVED' && isset($state[$id])) $state[$id]['status'] = 'approved';
            if ($entry['type'] === 'CONSUMED' && isset($state[$id])) $state[$id]['status'] = 'consumed';
        }
        return $state;
    }

    private function append(array $entry): void { $this->locked(fn() => $this->appendUnlocked($entry)); }
    private function appendUnlocked(array $entry): void
    {
        $line = PhpSerializer::encode($entry) . "\n";
        $handle = fopen($this->file, 'ab');
        if ($handle === false) {
            throw new RuntimeException('dual_control_write_failed');
        }
        try {
            if (fwrite($handle, $line) !== strlen($line) || !fflush($handle)) {
                throw new RuntimeException('dual_control_write_failed');
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('dual_control_sync_failed');
            }
        } finally {
            fclose($handle);
        }
        @chmod($this->file, 0600);
    }
    private function locked(callable $operation): mixed
    {
        $handle = fopen($this->lock, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('dual_control_lock_failed');
        try { return $operation(); } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
