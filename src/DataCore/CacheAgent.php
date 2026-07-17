<?php

declare(strict_types=1);

namespace Jah\DataCore;

final class CacheAgent
{
    private array $hot = [];
    private array $order = [];
    private int $limit = 10000;

    public function __construct(int $limit = 10000)
    {
        $this->limit = max(1, $limit);
    }

    public function get(string $key): mixed
    {
        if (isset($this->hot[$key])) {
            $this->hit($key);
            return $this->hot[$key];
        }
        return null;
    }

    public function set(string $key, mixed $value): void
    {
        if (array_key_exists($key, $this->hot)) {
            $this->order = array_values(array_diff($this->order, [$key]));
        } elseif (count($this->hot) >= $this->limit) {
            $old = array_shift($this->order);
            unset($this->hot[$old]);
        }
        $this->hot[$key] = $value;
        $this->order[] = $key;
    }

    private function hit(string $key): void
    {
        $this->order = array_values(array_diff($this->order, [$key]));
        $this->order[] = $key;
    }

    public function clear(): void
    {
        $this->hot = [];
        $this->order = [];
    }

    public function getAll(): array
    {
        return $this->hot;
    }

    public function getKeys(): array
    {
        return $this->order;
    }
}
