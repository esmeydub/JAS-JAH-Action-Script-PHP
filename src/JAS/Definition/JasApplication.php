<?php

declare(strict_types=1);

namespace Jah\JAS\Definition;

use Jah\JAS\Persistence\IdempotencyStore;
use Jah\JAS\Persistence\WalJournal;
use Jah\JAS\Persistence\EventJournal;
use Jah\JAS\Persistence\AuditJournal;
use Jah\JAS\Persistence\OutboxJournal;
use Jah\JAS\Runtime\GovernedRuntime;
use Jah\JAS\Security\CapabilityPolicy;
use Jah\JAS\Type\TypeRegistry;

final class JasApplication
{
    private ApplicationDefinition $definition;
    private TypeRegistry $types;

    public function __construct(string $name)
    {
        $this->definition = new ApplicationDefinition($name);
        $this->types = new TypeRegistry();
    }

    public function type(string $name, array $fields, bool $strict = true): self
    {
        $this->types->define($name, $fields, $strict);
        return $this;
    }

    public function domain(string $name, string $prefix, array $dependencies = []): self
    {
        $this->definition->domain($name, $prefix, $dependencies);
        return $this;
    }

    public function event(string $domain, string $name, string $payloadType, int $version = 1): self
    {
        $this->definition->defineEvent($domain, $name, $payloadType, $version);
        return $this;
    }

    public function action(string $domain, string $name): ActionDefinition
    {
        return $this->definition->defineAction($domain, $name);
    }

    public function validate(): self
    {
        $this->definition->validateForProduction();
        $description = $this->definition->describe();
        foreach ($description['contracts'] as $contract) {
            if (!$this->types->has((string) $contract['input']) || !$this->types->has((string) $contract['output'])) {
                throw new \InvalidArgumentException('action_type_not_defined');
            }
        }
        foreach ($description['events'] as $event) {
            if (!$this->types->has((string) $event['payload'])) throw new \InvalidArgumentException('event_type_not_defined');
        }
        return $this;
    }

    public function runtime(array $capabilities, string $principal, string $runtimeDirectory): GovernedRuntime
    {
        $this->validate();
        return new GovernedRuntime(
            $this->definition,
            $this->types,
            new CapabilityPolicy($capabilities),
            new WalJournal(rtrim($runtimeDirectory, '/') . '/wal'),
            $principal,
            new IdempotencyStore(rtrim($runtimeDirectory, '/') . '/idempotency'),
            new EventJournal(rtrim($runtimeDirectory, '/') . '/events'),
            new AuditJournal(rtrim($runtimeDirectory, '/') . '/audit'),
            new OutboxJournal(rtrim($runtimeDirectory, '/') . '/outbox')
        );
    }

    public function describe(): array
    {
        $definition = $this->definition->describe();
        $typeDescription = $this->types->describe();
        $definition['types'] = $typeDescription['definitions'];
        $definition['type_aliases'] = $typeDescription['aliases'];
        $definition['fingerprint'] = hash('sha256', serialize($definition));
        return $definition;
    }
}
