<?php

declare(strict_types=1);

namespace Jah\JAS\Runtime;

use Jah\JAS\Action\ActionGraph;
use Jah\JAS\Action\GraphScheduler;
use Jah\JAS\ObjectGraph\ObjectRuntime;
use Jah\JAS\Persistence\WalJournal;
use Jah\JAS\Security\CapabilityPolicy;
use Throwable;

final class JasRuntime
{
    /** @var array<string,array{handler:callable,capability:string}> */
    private array $actions = [];
    private GraphScheduler $scheduler;

    public function __construct(
        private readonly CapabilityPolicy $policy,
        private readonly WalJournal $wal,
        private readonly string $principal = 'jas.local',
        int $maxConcurrent = 16
    ) {
        $this->scheduler = new GraphScheduler(fn(string $action, array $payload, array $deps): array => $this->execute($action, $payload, $deps), $maxConcurrent);
    }

    public function register(string $name, string $capability, callable $handler): self
    {
        $this->actions[$name] = ['handler'=>$handler,'capability'=>$capability];
        return $this;
    }

    public function scheduler(): GraphScheduler { return $this->scheduler; }

    public function run(ActionGraph $graph): array { return $this->scheduler->run($graph); }

    public function execute(string $action, array $payload = [], array $dependencies = []): array
    {
        $definition = $this->actions[$action] ?? null;
        if (!$definition) return ['success'=>false,'error'=>'unknown_action','action'=>$action];
        $this->policy->assertAllowed($this->principal, $definition['capability']);
        $requestId = (string)($payload['_request_id'] ?? bin2hex(random_bytes(16)));
        $this->wal->begin($requestId, $action, $payload);
        try {
            $value = ($definition['handler'])($payload, $dependencies);
            $result = is_array($value) && array_key_exists('success', $value) ? $value : ['success'=>true,'result'=>$value];
            if (($result['success'] ?? false) === true) $this->wal->commit($requestId, $result);
            else $this->wal->abort($requestId, (string)($result['error'] ?? 'action_failed'));
            return $result + ['request_id'=>$requestId];
        } catch (Throwable $e) {
            $this->wal->abort($requestId, $e->getMessage());
            return [
                'success' => false,
                'error' => 'action_execution_failed',
                'request_id' => $requestId,
            ];
        }
    }

    public function recover(callable $replayer): array
    {
        $recovered = [];
        foreach ($this->wal->pending() as $tx => $entry) {
            $result = $replayer($entry['operation'], $entry['payload']);
            $this->wal->commit($tx, is_array($result) ? $result : ['result'=>$result]);
            $recovered[$tx] = $result;
        }
        return $recovered;
    }
}
