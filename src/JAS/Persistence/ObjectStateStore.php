<?php

declare(strict_types=1);

namespace Jah\JAS\Persistence;

use Jah\Memory\TieredMemory;
use Jah\JAS\ObjectGraph\ActiveObject;

final class ObjectStateStore
{
    public function __construct(private readonly TieredMemory $memory, private readonly string $collection = 'jas_objects') {}

    public function save(ActiveObject $object, string $tier = 'hot'): void
    {
        $this->memory->store($object->id, [
            'type' => $object->type,
            'state' => $object->state(),
            'bindings' => $object->bindings(),
            'version' => $object->version(),
            'updated_at' => microtime(true),
        ], $tier, $this->collection);
    }

    public function load(string $id): ?ActiveObject
    {
        $doc = $this->memory->retrieve($id, null, $this->collection);
        if (!is_array($doc)) return null;
        return ActiveObject::restore($id, (string)($doc['type'] ?? 'Object'), (array)($doc['state'] ?? []), (array)($doc['bindings'] ?? []), (int)($doc['version'] ?? 0));
    }
}
