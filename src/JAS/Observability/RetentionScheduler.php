<?php

declare(strict_types=1);

namespace Jah\JAS\Observability;

use Closure;
use Jah\DataCore\PhpSerializer;
use RuntimeException;

final class RetentionScheduler
{
    /** @var array<string,Closure> */
    private array $tasks = [];
    private readonly string $stateFile;
    private readonly string $lockFile;

    public function __construct(string $directory, private readonly int $intervalSeconds = 3_600)
    {
        if ($intervalSeconds < 60 || $intervalSeconds > 604_800) throw new RuntimeException('retention_interval_invalid');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('retention_directory_failed');
        }
        $this->stateFile = rtrim($directory, '/') . '/retention-state.jahl';
        $this->lockFile = rtrim($directory, '/') . '/retention.lock';
    }

    /** @param callable(bool):array $task */
    public function task(string $name, callable $task): self
    {
        if (preg_match('/^[a-z][a-z0-9_.:-]{2,127}$/', $name) !== 1 || isset($this->tasks[$name]) || count($this->tasks) >= 32) {
            throw new RuntimeException('retention_task_invalid');
        }
        $this->tasks[$name] = Closure::fromCallable($task);
        return $this;
    }

    /** @return array{due:bool,applied:bool,tasks:array<string,array>,last_success_at:?int} */
    public function run(bool $apply = false, bool $force = false, ?int $now = null): array
    {
        $now ??= time();
        $lock = fopen($this->lockFile, 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('retention_lock_failed');
        try {
            $state = $this->readState();
            $last = isset($state['last_success_at']) ? (int) $state['last_success_at'] : null;
            $due = $force || $last === null || $last + $this->intervalSeconds <= $now;
            if (!$due) return ['due' => false, 'applied' => false, 'tasks' => [], 'last_success_at' => $last];
            $reports = [];
            foreach ($this->tasks as $name => $task) {
                $report = $task($apply);
                if (!is_array($report)) throw new RuntimeException('retention_task_report_invalid');
                $reports[$name] = $report;
            }
            if ($apply) {
                $this->writeState(['last_success_at' => $now, 'tasks' => array_keys($reports)]);
                $last = $now;
            }
            return ['due' => true, 'applied' => $apply, 'tasks' => $reports, 'last_success_at' => $last];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function readState(): array
    {
        if (!is_file($this->stateFile)) return [];
        $state = PhpSerializer::decode(file_get_contents($this->stateFile));
        if (!is_array($state)) throw new RuntimeException('retention_state_corrupt');
        return $state;
    }

    private function writeState(array $state): void
    {
        $temporary = $this->stateFile . '.tmp.' . bin2hex(random_bytes(4));
        $payload = PhpSerializer::encode($state);
        $handle = fopen($temporary, 'xb');
        if ($handle === false) throw new RuntimeException('retention_state_prepare_failed');
        try {
            if (fwrite($handle, $payload) !== strlen($payload) || !fflush($handle)
                || (function_exists('fsync') && !@fsync($handle))) throw new RuntimeException('retention_state_write_failed');
        } finally { fclose($handle); }
        if (!rename($temporary, $this->stateFile)) { @unlink($temporary); throw new RuntimeException('retention_state_publish_failed'); }
        @chmod($this->stateFile, 0600);
    }
}
