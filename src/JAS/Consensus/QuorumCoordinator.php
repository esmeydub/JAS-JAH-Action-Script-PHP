<?php

declare(strict_types=1);

namespace Jah\JAS\Consensus;

use Jah\JAS\Cluster\NodeRegistry;
use RuntimeException;
use Throwable;

final class QuorumCoordinator
{
    public function __construct(
        private readonly NodeRegistry $registry,
        private readonly FencingTokenStore $fencing,
        private readonly int $minQuorum = 0,
    ) {
    }

    public function required(): int
    {
        $nodeCount = count($this->registry->all(true));
        return $this->minQuorum > 0 ? $this->minQuorum : intdiv($nodeCount, 2) + 1;
    }

    public function commit(
        string $leaderId,
        int $term,
        string $operationId,
        array $payload,
        callable $prepareRemote,
        callable $applyLocal,
    ): array {
        $nodes = $this->registry->all(true);
        if (!isset($nodes[$leaderId])) {
            throw new RuntimeException('leader_not_alive');
        }

        $fencing = $this->fencing->issue($leaderId, $term);
        $acknowledgements = 1;
        $errors = [];
        foreach ($nodes as $nodeId => $node) {
            if ($nodeId === $leaderId) continue;
            try {
                $response = $prepareRemote($node, [
                    'type' => 'QUORUM_PREPARE',
                    'operation_id' => $operationId,
                    'term' => $term,
                    'fencing_token' => $fencing['token'],
                    'payload' => $payload,
                ]);
                if (($response['accepted'] ?? false) === true) {
                    $acknowledgements++;
                } else {
                    $errors[$nodeId] = $response['error'] ?? 'rejected';
                }
            } catch (Throwable $error) {
                $errors[$nodeId] = $error->getMessage();
            }
        }

        $required = $this->required();
        if ($acknowledgements < $required) {
            throw new RuntimeException("quorum_not_reached:{$acknowledgements}/{$required}");
        }
        $this->fencing->assertValid($leaderId, $term, (int) $fencing['token']);
        $result = $applyLocal($payload, $fencing);

        return [
            'success' => true,
            'acks' => $acknowledgements,
            'required' => $required,
            'fencing' => $fencing,
            'result' => $result,
            'errors' => $errors,
        ];
    }
}
