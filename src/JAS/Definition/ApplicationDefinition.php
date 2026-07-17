<?php

declare(strict_types=1);

namespace Jah\JAS\Definition;

use InvalidArgumentException;

final class ApplicationDefinition
{
    /** @var array<string,DomainDefinition> */
    private array $domains = [];
    /** @var array<string,string> action => domain */
    private array $actions = [];
    /** @var array<string,ActionDefinition> */
    private array $contracts = [];
    /** @var array<string,EventDefinition> */
    private array $events = [];

    public function __construct(public readonly string $name)
    {
        if (!preg_match('/^[A-Z][A-Za-z0-9 _-]{2,127}$/', $name)) {
            throw new InvalidArgumentException('application_name_invalid');
        }
    }

    public function domain(string $name, string $actionPrefix, array $dependencies = []): self
    {
        if (isset($this->domains[$name])) throw new InvalidArgumentException('domain_already_defined');
        foreach ($this->domains as $domain) {
            if ($domain->actionPrefix === $actionPrefix) throw new InvalidArgumentException('domain_prefix_already_owned');
        }
        $this->domains[$name] = new DomainDefinition($name, $actionPrefix, $dependencies);
        return $this;
    }

    public function action(string $domainName, string $action): self
    {
        $domain = $this->domains[$domainName] ?? throw new InvalidArgumentException('domain_not_defined');
        if (!$domain->ownsAction($action)) throw new InvalidArgumentException('action_outside_domain');
        if (isset($this->actions[$action])) throw new InvalidArgumentException('action_already_defined');
        $this->actions[$action] = $domainName;
        return $this;
    }

    public function defineAction(string $domainName, string $action): ActionDefinition
    {
        $this->action($domainName, $action);
        return $this->contracts[$action] = new ActionDefinition($domainName, $action);
    }

    public function defineEvent(string $domainName, string $event, string $payloadType, int $version = 1): self
    {
        $domain = $this->domains[$domainName] ?? throw new InvalidArgumentException('domain_not_defined');
        if (!$domain->ownsAction($event)) throw new InvalidArgumentException('event_outside_domain');
        $key = $event . '@' . $version;
        if (isset($this->events[$key])) throw new InvalidArgumentException('event_version_already_defined');
        $this->events[$key] = new EventDefinition($domainName, $event, $payloadType, $version);
        return $this;
    }

    public function assertCallAllowed(string $callerAction, string $targetAction): void
    {
        $caller = $this->ownerOf($callerAction);
        $target = $this->ownerOf($targetAction);
        if ($caller === $target) return;
        if (!in_array($target, $this->domains[$caller]->dependencies, true)) {
            throw new InvalidArgumentException('cross_domain_dependency_not_declared');
        }
    }

    public function validate(): void
    {
        foreach ($this->domains as $domain) {
            foreach ($domain->dependencies as $dependency) {
                if (!isset($this->domains[$dependency])) throw new InvalidArgumentException('domain_dependency_not_defined');
            }
        }

        $visiting = [];
        $visited = [];
        $visit = function (string $name) use (&$visit, &$visiting, &$visited): void {
            if (isset($visited[$name])) return;
            if (isset($visiting[$name])) throw new InvalidArgumentException('domain_dependency_cycle');
            $visiting[$name] = true;
            foreach ($this->domains[$name]->dependencies as $dependency) $visit($dependency);
            unset($visiting[$name]);
            $visited[$name] = true;
        };
        foreach (array_keys($this->domains) as $name) $visit($name);
    }

    public function validateForProduction(): void
    {
        $this->validate();
        foreach ($this->actions as $action => $domain) {
            if (!isset($this->contracts[$action])) throw new InvalidArgumentException('action_contract_required');
            $this->contracts[$action]->validateForProduction();
            $emitted = $this->contracts[$action]->describe()['emits'] ?? null;
            if (is_string($emitted) && $emitted !== '' && !$this->hasEvent($emitted)) {
                throw new InvalidArgumentException('emitted_event_not_defined');
            }
        }
    }

    public function contract(string $action): ActionDefinition
    {
        return $this->contracts[$action] ?? throw new InvalidArgumentException('action_contract_not_defined');
    }

    public function event(string $name, ?int $version = null): EventDefinition
    {
        if ($version !== null) {
            return $this->events[$name . '@' . $version] ?? throw new InvalidArgumentException('event_not_defined');
        }
        $matches = array_values(array_filter($this->events, static fn(EventDefinition $event): bool => $event->name === $name));
        if ($matches === []) throw new InvalidArgumentException('event_not_defined');
        usort($matches, static fn(EventDefinition $a, EventDefinition $b): int => $b->version <=> $a->version);
        return $matches[0];
    }

    /** @return array{name:string,domains:array<string,array{prefix:string,dependencies:array}>,actions:array<string,string>,contracts:array<string,array>,events:array<string,array>} */
    public function describe(): array
    {
        $domains = [];
        foreach ($this->domains as $name => $domain) {
            $domains[$name] = ['prefix' => $domain->actionPrefix, 'dependencies' => $domain->dependencies];
        }
        ksort($domains);
        $actions = $this->actions;
        ksort($actions);
        $contracts = [];
        foreach ($this->contracts as $action => $contract) $contracts[$action] = $contract->describe();
        ksort($contracts);
        $events = [];
        foreach ($this->events as $key => $event) $events[$key] = $event->describe();
        ksort($events);
        return ['name' => $this->name, 'domains' => $domains, 'actions' => $actions, 'contracts' => $contracts, 'events' => $events];
    }

    private function ownerOf(string $action): string
    {
        return $this->actions[$action] ?? throw new InvalidArgumentException('action_not_defined');
    }

    private function hasEvent(string $name): bool
    {
        foreach ($this->events as $event) if ($event->name === $name) return true;
        return false;
    }
}
