<?php

declare(strict_types=1);

namespace Jah\JAS\Definition;

use InvalidArgumentException;

final class ActionDefinition
{
    private ?string $inputType = null;
    private ?string $outputType = null;
    private ?string $capability = null;
    private bool $audited = false;
    private bool $idempotent = false;
    private bool $transactional = false;
    private ?string $emittedEvent = null;
    private ?string $queue = null;
    private ?string $partitionField = null;
    private int $maxAttempts = 1;

    public function __construct(public readonly string $domain, public readonly string $name)
    {
        if (!preg_match('/^[a-z][a-z0-9_.:-]{2,255}$/', $name)) throw new InvalidArgumentException('action_name_invalid');
    }

    public function input(string $type): self { $this->inputType = $this->typeName($type); return $this; }
    public function output(string $type): self { $this->outputType = $this->typeName($type); return $this; }

    public function requires(string $capability): self
    {
        if (!preg_match('/^[a-z][a-z0-9_.:*\-]{2,255}$/', $capability)) throw new InvalidArgumentException('action_capability_invalid');
        $this->capability = $capability;
        return $this;
    }

    public function audit(bool $enabled = true): self { $this->audited = $enabled; return $this; }
    public function idempotent(bool $enabled = true): self { $this->idempotent = $enabled; return $this; }
    public function transactional(bool $enabled = true): self { $this->transactional = $enabled; return $this; }

    public function emits(string $event): self
    {
        if (!preg_match('/^[a-z][a-z0-9_.:-]{2,255}$/', $event)) throw new InvalidArgumentException('action_event_invalid');
        $this->emittedEvent = $event;
        return $this;
    }

    public function queued(string $queue, string $partitionField, int $maxAttempts = 3): self
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,127}$/', $queue)) throw new InvalidArgumentException('action_queue_invalid');
        if (!preg_match('/^[a-z_][a-z0-9_]{0,127}$/', $partitionField)) throw new InvalidArgumentException('action_partition_field_invalid');
        if ($maxAttempts < 1 || $maxAttempts > 100) throw new InvalidArgumentException('action_max_attempts_invalid');
        $this->queue = $queue;
        $this->partitionField = $partitionField;
        $this->maxAttempts = $maxAttempts;
        return $this;
    }

    public function validateForProduction(): void
    {
        if ($this->inputType === null) throw new InvalidArgumentException('action_input_type_required');
        if ($this->outputType === null) throw new InvalidArgumentException('action_output_type_required');
        if ($this->capability === null) throw new InvalidArgumentException('action_capability_required');
        if (!$this->audited) throw new InvalidArgumentException('action_audit_required');
        if (($this->transactional || $this->queue !== null) && !$this->idempotent) {
            throw new InvalidArgumentException('action_idempotency_required');
        }
    }

    public function describe(): array
    {
        return [
            'domain' => $this->domain, 'name' => $this->name,
            'input' => $this->inputType, 'output' => $this->outputType,
            'capability' => $this->capability, 'audit' => $this->audited,
            'idempotent' => $this->idempotent, 'transactional' => $this->transactional,
            'emits' => $this->emittedEvent, 'queue' => $this->queue,
            'partition_by' => $this->partitionField, 'max_attempts' => $this->maxAttempts,
        ];
    }

    private function typeName(string $type): string
    {
        if (!preg_match('/^[A-Z][A-Za-z0-9_]{1,127}$/', $type)) throw new InvalidArgumentException('action_type_invalid');
        return $type;
    }
}
