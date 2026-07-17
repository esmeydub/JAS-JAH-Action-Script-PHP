<?php

declare(strict_types=1);

namespace Jah\JAS\Worker;

use RuntimeException;

final class WorkerRegistry
{
    /** @var array<string,WorkerDescriptor> */
    private array $workers = [];
    public function register(WorkerDescriptor $worker): void { $this->workers[$worker->id] = $worker; }
    public function heartbeat(string $id): void
    {
        if (!isset($this->workers[$id])) throw new RuntimeException("Worker desconocido: {$id}");
        $this->workers[$id]->lastHeartbeat = time();
    }
    public function acquire(string $capability, int $staleAfter = 30): WorkerDescriptor
    {
        $now = time();
        $candidates = array_filter($this->workers, static fn(WorkerDescriptor $w): bool => ($now-$w->lastHeartbeat)<= $staleAfter && $w->supports($capability) && $w->available());
        if ($candidates === []) throw new RuntimeException("No hay worker disponible para {$capability}");
        usort($candidates, static fn(WorkerDescriptor $a, WorkerDescriptor $b): int => ($a->inFlight/$a->capacity) <=> ($b->inFlight/$b->capacity));
        $worker = $candidates[0]; $worker->inFlight++; return $worker;
    }
    public function release(string $id): void { if (isset($this->workers[$id])) $this->workers[$id]->inFlight = max(0, $this->workers[$id]->inFlight-1); }
    /** @return array<string,WorkerDescriptor> */ public function all(): array { return $this->workers; }

    /** @return list<WorkerDescriptor> */
    public function availableWorkers(int $staleAfter = 30): array
    {
        $now = time();
        $workers = array_values(array_filter($this->workers, static fn(WorkerDescriptor $w): bool => ($now - $w->lastHeartbeat) <= $staleAfter && $w->available()));
        usort($workers, static fn(WorkerDescriptor $a, WorkerDescriptor $b): int => ($a->inFlight / $a->capacity) <=> ($b->inFlight / $b->capacity));
        return $workers;
    }

    public function reserve(string $id): WorkerDescriptor
    {
        $worker = $this->workers[$id] ?? null;
        if (!$worker || !$worker->available()) throw new RuntimeException("Worker no disponible: {$id}");
        $worker->inFlight++;
        return $worker;
    }
}
