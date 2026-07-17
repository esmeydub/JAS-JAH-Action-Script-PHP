<?php

declare(strict_types=1);

namespace Jah\JAS\Runtime;

use Jah\DataCore\PhpSerializer;
use Jah\JAS\ObjectGraph\ObjectRuntime;
use Jah\JAS\Queue\JobService;
use Jah\JAS\Protocol\JasBinaryCodec;
use Jah\JAS\Protocol\JasPacket;
use Jah\JAS\Protocol\Opcodes;
use Jah\JAS\Security\SalkRuntimeGuard;
use Throwable;

final class BinaryRuntime
{
    public function __construct(
        private readonly JasBinaryCodec $codec,
        private readonly SalkRuntimeGuard $guard,
        private readonly JasRuntime $runtime,
        private readonly ObjectRuntime $objects,
        private readonly string $principal = 'jas.native',
        private readonly ?JobService $jobs = null
    ) {}

    public function handle(string $binary): string
    {
        $packet = $this->codec->decode($binary);
        try {
            $payload = PhpSerializer::decode($packet->payload);
            $payload = is_array($payload) ? $payload : [];
            $capability = $this->capabilityFor($packet->opcode, $payload);
            $this->guard->authorize($packet, $this->principal, $capability);

            $result = match ($packet->opcode) {
                Opcodes::PING => ['success'=>true,'result'=>'PONG'],
                Opcodes::ACTION_EXECUTE => $this->runtime->execute((string)($payload['action'] ?? ''), (array)($payload['payload'] ?? [])),
                Opcodes::OBJECT_EVENT => $this->objects->emit($packet->objectId, (string)($payload['event'] ?? ''), (array)($payload['payload'] ?? [])),
                Opcodes::OBJECT_STATE_GET => ($object = $this->objects->object($packet->objectId))
                    ? ['success'=>true,'result'=>['state'=>$object->state(),'version'=>$object->version(),'type'=>$object->type]]
                    : ['success'=>false,'error'=>'object_not_found'],
                Opcodes::JOB_SUBMIT => $this->jobs?->submit($payload) ?? ['success'=>false,'error'=>'job_service_unavailable'],
                Opcodes::JOB_STATUS => $this->jobs?->status((string)($payload['job_id'] ?? $packet->objectId)) ?? ['success'=>false,'error'=>'job_service_unavailable'],
                Opcodes::JOB_CANCEL => $this->jobs?->cancel((string)($payload['job_id'] ?? $packet->objectId)) ?? ['success'=>false,'error'=>'job_service_unavailable'],
                Opcodes::QUEUE_STATS => $this->jobs?->stats() ?? ['success'=>false,'error'=>'job_service_unavailable'],
                default => ['success'=>false,'error'=>'unsupported_opcode'],
            };
            return $this->response($packet, ($result['success'] ?? false) ? Opcodes::RESULT : Opcodes::ERROR, $result);
        } catch (Throwable $e) {
            return $this->response($packet, Opcodes::ERROR, ['success'=>false,'error'=>$e->getMessage()]);
        }
    }

    private function capabilityFor(int $opcode, array $payload): string
    {
        return match ($opcode) {
            Opcodes::PING => 'protocol.ping',
            Opcodes::ACTION_EXECUTE => 'action.' . (string)($payload['action'] ?? 'unknown'),
            Opcodes::OBJECT_EVENT => 'object.event.emit',
            Opcodes::OBJECT_STATE_GET => 'object.state.read',
            Opcodes::JOB_SUBMIT => 'queue.job.submit',
            Opcodes::JOB_STATUS => 'queue.job.read',
            Opcodes::JOB_CANCEL => 'queue.job.cancel',
            Opcodes::QUEUE_STATS => 'queue.stats.read',
            default => 'protocol.unknown',
        };
    }

    private function response(JasPacket $request, int $opcode, array $payload): string
    {
        return $this->codec->encode(new JasPacket(
            $opcode,
            0,
            $request->requestId . ':response',
            $request->objectId,
            PhpSerializer::encode($payload),
            time()
        ));
    }
}
