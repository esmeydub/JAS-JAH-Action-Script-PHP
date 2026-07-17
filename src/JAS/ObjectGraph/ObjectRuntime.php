<?php

declare(strict_types=1);

namespace Jah\JAS\ObjectGraph;

use Jah\JAS\Action\ActionGraph;
use Jah\JAS\Action\ActionNode;
use Jah\JAS\Action\GraphScheduler;
use Jah\JAS\Persistence\ObjectStateStore;
use InvalidArgumentException;

final class ObjectRuntime
{
    /** @var array<string,ActiveObject> */
    private array $objects = [];

    public function __construct(private readonly GraphScheduler $scheduler, private readonly ?ObjectStateStore $store = null) {}

    public function register(ActiveObject $object, bool $persist = true): self
    {
        $this->objects[$object->id] = $object;
        if ($persist && $this->store) $this->store->save($object);
        return $this;
    }

    public function object(string $id): ?ActiveObject
    {
        if (isset($this->objects[$id])) return $this->objects[$id];
        $loaded = $this->store?->load($id);
        if ($loaded) $this->objects[$id] = $loaded;
        return $loaded;
    }

    public function emit(string $objectId, string $event, array $payload = []): array
    {
        $object = $this->object($objectId);
        if (!$object) throw new InvalidArgumentException("Objeto JAS no encontrado: {$objectId}");

        $graph = new ActionGraph();
        $previous = null;
        foreach ($object->actionsFor($event) as $index => $action) {
            $id = $objectId . ':' . $event . ':' . $index;
            $graph->add(new ActionNode(
                id: $id,
                action: $action,
                payload: ['object_id' => $objectId, 'event' => $event, 'state' => $object->state(), 'version' => $object->version(), 'data' => $payload],
                dependencies: $previous === null ? [] : [$previous]
            ));
            $previous = $id;
        }
        if ($graph->nodes() === []) return ['success'=>true,'results'=>[],'failed'=>[]];
        $result = $this->scheduler->run($graph);
        if ($result['success'] === true) {
            foreach ($result['results'] as $entry) {
                $patch = $entry['state_patch'] ?? $entry['result']['state_patch'] ?? null;
                if (is_array($patch)) $object->patch($patch);
            }
            if ($this->store) $this->store->save($object);
        }
        return $result + ['object_version' => $object->version(), 'object_state' => $object->state()];
    }
}
