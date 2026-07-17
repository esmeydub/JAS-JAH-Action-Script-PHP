<?php

declare(strict_types=1);

namespace Jah\DataCore;

use RuntimeException;

final class DataCoreTransaction
{
    private array $operations = [];
    private bool $closed = false;

    public function __construct(public readonly string $id)
    {
    }

    public function insert(string $collection, array $document): self
    {
        $this->assertOpen();
        $this->operations[] = [
            'type' => 'insert',
            'collection' => $collection,
            'document' => $document,
        ];
        return $this;
    }

    public function update(
        string $collection,
        string $id,
        array $document,
        int $expectedVersion,
    ): self {
        $this->assertOpen();
        $this->operations[] = [
            'type' => 'update',
            'collection' => $collection,
            'id' => $id,
            'document' => $document,
            'expected_version' => $expectedVersion,
        ];
        return $this;
    }

    public function delete(string $collection, string $id, int $expectedVersion): self
    {
        $this->assertOpen();
        $this->operations[] = [
            'type' => 'delete',
            'collection' => $collection,
            'id' => $id,
            'expected_version' => $expectedVersion,
        ];
        return $this;
    }

    public function operations(): array
    {
        return $this->operations;
    }

    public function close(): void
    {
        $this->assertOpen();
        $this->closed = true;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new RuntimeException('datacore_transaction_closed');
        }
    }
}
