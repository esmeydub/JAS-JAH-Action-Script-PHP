<?php

declare(strict_types=1);

namespace Jah\JAS\Runtime;

use Jah\JAS\Definition\ApplicationDefinition;
use Jah\JAS\Persistence\WalJournal;
use Jah\JAS\Persistence\IdempotencyStore;
use Jah\JAS\Persistence\EventJournal;
use Jah\JAS\Persistence\AuditJournal;
use Jah\JAS\Persistence\OutboxJournal;
use Jah\JAS\Security\CapabilityPolicy;
use Jah\JAS\Type\TypeRegistry;
use Jah\DataCore\PhpSerializer;
use RuntimeException;
use Throwable;

final class GovernedRuntime
{
    /** @var array<string,callable> */
    private array $handlers = [];

    public function __construct(
        private readonly ApplicationDefinition $application,
        private readonly TypeRegistry $types,
        private readonly CapabilityPolicy $policy,
        private readonly WalJournal $wal,
        private readonly string $principal,
        private readonly ?IdempotencyStore $idempotency = null,
        private readonly ?EventJournal $events = null,
        private readonly ?AuditJournal $audit = null,
        private readonly ?OutboxJournal $outbox = null
    ) {
        $this->application->validateForProduction();
        if ($this->audit === null) throw new RuntimeException('audit_journal_required');
        if ($this->outbox === null) throw new RuntimeException('outbox_journal_required');
    }

    public function handle(string $action, callable $handler): self
    {
        $this->application->contract($action);
        if (isset($this->handlers[$action])) throw new RuntimeException('action_handler_already_registered');
        $this->handlers[$action] = $handler;
        return $this;
    }

    public function execute(string $action, array $input, ?string $requestId = null): array
    {
        $contract = $this->application->contract($action)->describe();
        $handler = $this->handlers[$action] ?? throw new RuntimeException('action_handler_not_registered');
        $inputType = (string) $contract['input'];
        $outputType = (string) $contract['output'];
        if (!$this->types->has($inputType) || !$this->types->has($outputType)) throw new RuntimeException('action_type_not_defined');
        $this->policy->assertAllowed($this->principal, (string) $contract['capability']);
        $this->types->assert($inputType, $input, 'input');
        $requestId ??= bin2hex(random_bytes(16));
        $inputFingerprint = hash('sha256', PhpSerializer::encode($input));
        if (($contract['idempotent'] ?? false) === true) {
            if ($this->idempotency === null) throw new RuntimeException('idempotency_store_required');
            return $this->idempotency->executeOnce(
                $action,
                $requestId,
                $inputFingerprint,
                fn(): array => $this->perform(
                    $action,
                    $input,
                    $outputType,
                    $requestId,
                    $handler,
                    $contract,
                    true,
                ),
                fn(): null => $this->markOutboxApplied($requestId),
            );
        }
        return $this->perform($action, $input, $outputType, $requestId, $handler, $contract);
    }

    private function perform(
        string $action,
        array $input,
        string $outputType,
        string $requestId,
        callable $handler,
        array $contract,
        bool $deferOutboxApplied = false,
    ): array
    {
        $fingerprint = hash('sha256', PhpSerializer::encode($input));
        $this->wal->begin($requestId, $action, $input);
        try {
            $output = $handler($input);
            $this->types->assert($outputType, $output, 'output');
            $result = ['success' => true, 'result' => $output, 'request_id' => $requestId, 'action' => $action];
            $emitted = $contract['emits'] ?? null;
            $eventRecord = null;
            if (is_string($emitted) && $emitted !== '') {
                if ($this->events === null) throw new RuntimeException('event_journal_required');
                $event = $this->application->event($emitted);
                $this->types->assert($event->payloadType, $output, 'event_payload');
                $eventRecord = ['name' => $event->name, 'version' => $event->version, 'payload' => $output];
            }
            $prepared = [
                'result' => $result, 'event' => $eventRecord,
                'audit' => (bool) ($contract['audit'] ?? false), 'principal' => $this->principal,
                'input_fingerprint' => $fingerprint, 'idempotent' => (bool) ($contract['idempotent'] ?? false),
            ];
            $this->outbox->prepare($requestId, $action, $prepared);
            $result = $this->publishPrepared($requestId, $action, $prepared);
            $this->wal->commit($requestId, $result);
            if (!$deferOutboxApplied) $this->outbox->applied($requestId);
            return $result;
        } catch (Throwable $error) {
            $this->wal->abort($requestId, $error->getMessage());
            if (($contract['audit'] ?? false) === true) {
                $this->audit->record($this->principal, $action, $requestId, false, $fingerprint, 'action_failed');
            }
            throw $error;
        }
    }

    private function markOutboxApplied(string $requestId): null
    {
        $this->outbox->applied($requestId);
        return null;
    }

    public function recoverOutbox(): int
    {
        $count = 0;
        foreach ($this->outbox->pending() as $requestId => $entry) {
            $action = (string) ($entry['action'] ?? '');
            $record = (array) ($entry['record'] ?? []);
            $result = $this->publishPrepared((string) $requestId, $action, $record);
            $this->wal->commit((string) $requestId, $result);
            if (($record['idempotent'] ?? false) === true && $this->idempotency !== null) {
                $this->idempotency->put($action, (string) $requestId, (string) $record['input_fingerprint'], $result);
            }
            $this->outbox->applied((string) $requestId);
            $count++;
        }
        return $count;
    }

    private function publishPrepared(string $requestId, string $action, array $record): array
    {
        $result = (array) ($record['result'] ?? []);
        $event = $record['event'] ?? null;
        if (is_array($event)) {
            if ($this->events === null) throw new RuntimeException('event_journal_required');
            $result['event'] = $this->events->append((string) $event['name'], (int) $event['version'], $requestId, (array) $event['payload']);
        }
        if (($record['audit'] ?? false) === true) {
            $this->audit->record((string) $record['principal'], $action, $requestId, true, (string) $record['input_fingerprint']);
        }
        return $result;
    }
}
