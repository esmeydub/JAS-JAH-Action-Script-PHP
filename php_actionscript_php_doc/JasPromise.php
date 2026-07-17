<?php

declare(strict_types=1);

namespace Jah;

use Throwable;

final class JasPromise
{
    private const PENDING = 'pending';
    private const FULFILLED = 'fulfilled';
    private const REJECTED = 'rejected';

    private string $state = self::PENDING;
    private mixed $value = null;
    private array $handlers = [];

    public function __construct(?callable $executor = null)
    {
        if ($executor !== null) {
            try {
                $executor(
                    fn(mixed $value): mixed => $this->fulfill($value),
                    fn(mixed $reason): mixed => $this->rejectValue($reason)
                );
            } catch (Throwable $error) {
                $this->rejectValue($error);
            }
        }
    }

    public static function resolve(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        $promise = new self();
        $promise->fulfill($value);
        return $promise;
    }

    public static function reject(mixed $reason): self
    {
        $promise = new self();
        $promise->rejectValue($reason);
        return $promise;
    }

    public static function all(iterable $promises): self
    {
        try {
            $values = [];
            foreach ($promises as $key => $promise) {
                $values[$key] = self::resolve($promise)->await();
            }
            return self::resolve($values);
        } catch (Throwable $error) {
            return self::reject($error);
        }
    }

    public function then(?callable $fulfilled = null, ?callable $rejected = null): self
    {
        $next = new self();
        $this->handlers[] = [$fulfilled, $rejected, $next];
        $this->drain();
        return $next;
    }

    public function catch(callable $rejected): self
    {
        return $this->then(null, $rejected);
    }

    public function finally(callable $callback): self
    {
        return $this->then(
            static function (mixed $value) use ($callback): mixed {
                $callback();
                return $value;
            },
            static function (mixed $reason) use ($callback): never {
                $callback();
                throw self::asThrowable($reason);
            }
        );
    }

    public function await(): mixed
    {
        if ($this->state === self::PENDING) {
            throw new \RuntimeException('Promise is still pending');
        }
        if ($this->state === self::REJECTED) {
            throw self::asThrowable($this->value);
        }
        return $this->value;
    }

    private function fulfill(mixed $value): mixed
    {
        if ($value instanceof self) {
            $value->then([$this, 'fulfill'], [$this, 'rejectValue']);
            return null;
        }
        if ($this->state === self::PENDING) {
            $this->state = self::FULFILLED;
            $this->value = $value;
            $this->drain();
        }
        return null;
    }

    private function rejectValue(mixed $reason): mixed
    {
        if ($this->state === self::PENDING) {
            $this->state = self::REJECTED;
            $this->value = $reason;
            $this->drain();
        }
        return null;
    }

    private function drain(): void
    {
        if ($this->state === self::PENDING) {
            return;
        }
        while ($handler = array_shift($this->handlers)) {
            [$fulfilled, $rejected, $next] = $handler;
            $callback = $this->state === self::FULFILLED ? $fulfilled : $rejected;
            if ($callback === null) {
                $this->state === self::FULFILLED
                    ? $next->fulfill($this->value)
                    : $next->rejectValue($this->value);
                continue;
            }
            try {
                $next->fulfill($callback($this->value));
            } catch (Throwable $error) {
                $next->rejectValue($error);
            }
        }
    }

    private static function asThrowable(mixed $reason): Throwable
    {
        return $reason instanceof Throwable
            ? $reason
            : new \RuntimeException(is_scalar($reason) ? (string) $reason : 'Promise rejected');
    }
}
