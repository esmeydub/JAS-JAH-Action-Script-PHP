<?php

declare(strict_types=1);

namespace Jah;

use IteratorAggregate;
use Traversable;

final class JasStream implements IteratorAggregate
{
    private array $operations = [];
    private JasEventEmitter $events;

    private function __construct(private iterable $source)
    {
        $this->events = new JasEventEmitter();
    }

    public static function from(iterable $source): self
    {
        return new self($source);
    }

    public function __clone()
    {
        $this->events = new JasEventEmitter();
    }

    public function map(callable $mapper): self
    {
        $next = clone $this;
        $next->operations[] = ['map', $mapper];
        return $next;
    }

    public function pipe(callable $mapper): self
    {
        return $this->map($mapper);
    }

    public function filter(callable $filter): self
    {
        $next = clone $this;
        $next->operations[] = ['filter', $filter];
        return $next;
    }

    public function reduce(callable $reducer, mixed $initial = null): mixed
    {
        $value = $initial;
        foreach ($this as $item) {
            $value = $reducer($value, $item);
        }
        return $value;
    }

    public function on(string $event, callable $listener): self
    {
        $this->events->on($event, $listener);
        if ($event === 'data') {
            foreach ($this as $item) {
                $this->events->emit('data', $item);
            }
            $this->events->emit('end');
        }
        return $this;
    }

    public function toArray(): array
    {
        return iterator_to_array($this->getIterator(), false);
    }

    public function getIterator(): Traversable
    {
        foreach ($this->source as $item) {
            $accepted = true;
            foreach ($this->operations as [$type, $operation]) {
                if ($type === 'map') {
                    $item = $operation($item);
                } elseif (!$operation($item)) {
                    $accepted = false;
                    break;
                }
            }
            if ($accepted) {
                yield $item;
            }
        }
    }
}
