<?php

declare(strict_types=1);

namespace Jah\JAS\Queue;

use InvalidArgumentException;

final class Job
{
    public const QUEUED = 'queued';
    public const LEASED = 'leased';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    private const STATES = [self::QUEUED, self::LEASED, self::COMPLETED, self::FAILED, self::CANCELLED];

    public function __construct(
        public readonly string $id,
        public readonly string $action,
        public readonly array $payload,
        public readonly string $capability,
        public readonly int $priority = 0,
        public readonly int $maxAttempts = 3,
        public readonly float $createdAt = 0.0,
        public readonly ?string $objectId = null,
        public readonly ?string $deduplicationKey = null,
        public string $state = self::QUEUED,
        public int $attempts = 0,
        public ?string $workerId = null,
        public ?float $leasedUntil = null,
        public mixed $result = null,
        public ?string $error = null
    ) {
        if ($id === '' || strlen($id) > 128 || $action === '' || strlen($action) > 255 || $capability === '' || strlen($capability) > 255) {
            throw new InvalidArgumentException('Trabajo JAS inválido');
        }
        if ($maxAttempts < 1) throw new InvalidArgumentException('maxAttempts debe ser mayor que cero');
        if (!in_array($state, self::STATES, true)) throw new InvalidArgumentException('Estado de trabajo JAS inválido');
        if ($attempts < 0 || $attempts > $maxAttempts) throw new InvalidArgumentException('Intentos de trabajo JAS inválidos');
        if ($state === self::LEASED && ($workerId === null || $workerId === '' || $leasedUntil === null)) {
            throw new InvalidArgumentException('Lease de trabajo JAS inválido');
        }
    }

    public static function create(
        string $action,
        array $payload,
        string $capability,
        int $priority = 0,
        int $maxAttempts = 3,
        ?string $objectId = null,
        ?string $deduplicationKey = null,
    ): self
    {
        return new self(
            bin2hex(random_bytes(16)),
            $action,
            $payload,
            $capability,
            $priority,
            $maxAttempts,
            microtime(true),
            $objectId,
            $deduplicationKey
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string)($data['id'] ?? ''),
            (string)($data['action'] ?? ''),
            (array)($data['payload'] ?? []),
            (string)($data['capability'] ?? ''),
            (int)($data['priority'] ?? 0),
            (int)($data['maxAttempts'] ?? 3),
            (float)($data['createdAt'] ?? microtime(true)),
            isset($data['objectId']) ? (string)$data['objectId'] : null,
            isset($data['deduplicationKey']) ? (string)$data['deduplicationKey'] : null,
            (string)($data['state'] ?? self::QUEUED),
            (int)($data['attempts'] ?? 0),
            isset($data['workerId']) ? (string)$data['workerId'] : null,
            isset($data['leasedUntil']) ? (float)$data['leasedUntil'] : null,
            $data['result'] ?? null,
            isset($data['error']) ? (string)$data['error'] : null
        );
    }
}
