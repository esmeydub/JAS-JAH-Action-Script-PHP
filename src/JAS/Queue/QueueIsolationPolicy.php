<?php

declare(strict_types=1);

namespace Jah\JAS\Queue;

use RuntimeException;

final class QueueIsolationPolicy
{
    /** @var array<string,array{max_active:int,max_leased:int}> */
    private array $partitions = [];

    /** @param array<string,array{max_active:int,max_leased:int}> $partitions */
    public function __construct(
        private readonly int $defaultMaxActive,
        private readonly int $defaultMaxLeased,
        array $partitions = [],
    ) {
        if ($defaultMaxActive < 1 || $defaultMaxLeased < 1 || $defaultMaxLeased > $defaultMaxActive) {
            throw new RuntimeException('queue_isolation_default_invalid');
        }
        foreach ($partitions as $name => $limits) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $name) !== 1
                || !is_array($limits) || (int) ($limits['max_active'] ?? 0) < 1
                || (int) ($limits['max_leased'] ?? 0) < 1
                || (int) $limits['max_leased'] > (int) $limits['max_active']) {
                throw new RuntimeException('queue_isolation_partition_invalid');
            }
            $this->partitions[$name] = ['max_active' => (int) $limits['max_active'], 'max_leased' => (int) $limits['max_leased']];
        }
    }

    /** @param array<string,Job> $jobs */
    public function assertSubmit(Job $job, array $jobs): void
    {
        $partition = $this->partition($job);
        $state = $this->partitionState($partition, $jobs);
        if ($state['active'] >= $state['max_active']) throw new RuntimeException('queue_partition_full:' . $partition);
    }

    /** @param array<string,Job> $jobs */
    public function canLease(Job $job, array $jobs): bool
    {
        $state = $this->partitionState($this->partition($job), $jobs);
        return $state['leased'] < $state['max_leased'];
    }

    /** @param array<string,Job> $jobs @return array<string,array{queued:int,leased:int,active:int,max_active:int,max_leased:int,saturated:bool}> */
    public function stats(array $jobs): array
    {
        $names = array_keys($this->partitions);
        foreach ($jobs as $job) $names[] = $this->partition($job);
        $states = [];
        foreach (array_values(array_unique($names)) as $name) $states[$name] = $this->partitionState($name, $jobs);
        ksort($states);
        return $states;
    }

    public function partition(Job $job): string
    {
        foreach ([$job->action, $job->capability] as $candidate) {
            if (preg_match('/^([a-z][a-z0-9_-]{0,63})[.:]/', $candidate, $match) === 1) return $match[1];
        }
        return 'default';
    }

    /** @param array<string,Job> $jobs @return array{queued:int,leased:int,active:int,max_active:int,max_leased:int,saturated:bool} */
    private function partitionState(string $partition, array $jobs): array
    {
        $limits = $this->partitions[$partition] ?? ['max_active' => $this->defaultMaxActive, 'max_leased' => $this->defaultMaxLeased];
        $queued = 0;
        $leased = 0;
        foreach ($jobs as $job) {
            if ($this->partition($job) !== $partition) continue;
            if ($job->state === Job::QUEUED) $queued++;
            elseif ($job->state === Job::LEASED) $leased++;
        }
        $active = $queued + $leased;
        return [
            'queued' => $queued, 'leased' => $leased, 'active' => $active,
            'max_active' => $limits['max_active'], 'max_leased' => $limits['max_leased'],
            'saturated' => $active >= $limits['max_active'] || $leased >= $limits['max_leased'],
        ];
    }
}
