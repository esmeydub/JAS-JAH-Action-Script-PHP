<?php

declare(strict_types=1);

namespace Jah\DataCore;

final class SqlMirrorWorker
{
    public function __construct(
        private readonly SqlMirrorOutbox $outbox,
        private readonly PdoSqlMirror $mirror,
        private readonly ?SqlMirrorResilienceStore $resilience = null,
    ) {
    }

    public function synchronize(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 10_000) {
            throw new \RuntimeException('sql_mirror_limit_invalid');
        }
        if ($this->resilience?->isOpen() === true) {
            return ['applied' => 0, 'failed' => 0, 'quarantined' => 0, 'remaining' => count($this->outbox->pending()), 'circuit_open' => true];
        }
        $applied = 0;
        $failed = 0;
        $quarantined = 0;
        foreach (array_slice($this->outbox->pending(), 0, $limit, true) as $operationId => $entry) {
            if ($this->resilience?->isQuarantined((string) $operationId) === true) {
                $quarantined++;
                continue;
            }
            try {
                $this->mirror->apply($entry);
                $this->outbox->applied((string) $operationId);
                $this->resilience?->success((string) $operationId);
                $applied++;
            } catch (\Throwable $error) {
                $failed++;
                $failure = $this->resilience?->failure((string) $operationId, $error->getMessage());
                if (($failure['quarantined'] ?? false) === true) $quarantined++;
                if ($this->resilience?->isOpen() === true) break;
            }
        }
        return [
            'applied' => $applied,
            'failed' => $failed,
            'quarantined' => $quarantined,
            'remaining' => count($this->outbox->pending()),
            'circuit_open' => $this->resilience?->isOpen() ?? false,
        ];
    }
}
