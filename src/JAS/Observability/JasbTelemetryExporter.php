<?php

declare(strict_types=1);

namespace Jah\JAS\Observability;

use Jah\DataCore\PhpSerializer;
use Jah\JAS\Protocol\JasBinaryCodec;
use Jah\JAS\Protocol\JasPacket;
use Jah\JAS\Protocol\Opcodes;
use RuntimeException;
use Throwable;

final class JasbTelemetryExporter
{
    private const MAX_METRICS_PER_KIND = 1_024;
    private const MAX_TRACES = 1_000;
    private const MAX_PAYLOAD_BYTES = 1_048_576;
    private const TRACE_CONTEXT_KEYS = [
        'request_id', 'trace_id', 'span_id', 'parent_span_id', 'method', 'route',
        'status', 'duration_ms', 'error_code', 'component', 'operation',
    ];

    public function __construct(
        private readonly JasBinaryCodec $codec,
        private readonly TelemetryAdapter $adapter,
    ) {}

    /** @return array{opcode:int,count:int,bytes:int,request_id:string} */
    public function metrics(array $snapshot, string $nodeId): array
    {
        $metrics = $this->normalizeMetrics($snapshot);
        $count = count($metrics['counters']) + count($metrics['gauges']) + count($metrics['timings']);
        return $this->export(Opcodes::TELEMETRY_METRICS, $nodeId, ['schema' => 'JAS_TELEMETRY_V1', 'metrics' => $metrics], $count);
    }

    /** @param list<array> $records @return array{opcode:int,count:int,bytes:int,request_id:string} */
    public function traces(array $records, string $nodeId, int $limit = 500): array
    {
        if (!array_is_list($records) || $limit < 1 || $limit > self::MAX_TRACES) throw new RuntimeException('telemetry_trace_batch_invalid');
        $traces = [];
        foreach (array_slice($records, 0, $limit) as $record) {
            if (!is_array($record)) throw new RuntimeException('telemetry_trace_invalid');
            $traces[] = $this->normalizeTrace($record);
        }
        return $this->export(Opcodes::TELEMETRY_TRACES, $nodeId, ['schema' => 'JAS_TELEMETRY_V1', 'traces' => $traces], count($traces));
    }

    /** @return array{opcode:int,count:int,bytes:int,request_id:string} */
    private function export(int $opcode, string $nodeId, array $data, int $count): array
    {
        if (preg_match('/^[A-Za-z0-9._:-]{3,128}$/', $nodeId) !== 1) throw new RuntimeException('telemetry_node_invalid');
        $payload = PhpSerializer::encode($data + ['node_id' => $nodeId, 'generated_at' => microtime(true)]);
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) throw new RuntimeException('telemetry_payload_too_large');
        $requestId = 'telemetry-' . bin2hex(random_bytes(12));
        $packet = $this->codec->encode(new JasPacket($opcode, 0, $requestId, $nodeId, $payload, time()));
        try {
            $this->adapter->send($packet);
        } catch (Throwable $error) {
            throw new RuntimeException('telemetry_adapter_failed', 0, $error);
        }
        return ['opcode' => $opcode, 'count' => $count, 'bytes' => strlen($packet), 'request_id' => $requestId];
    }

    /** @return array{counters:array<string,int|float>,gauges:array<string,int|float>,timings:array<string,array>,updated_at:int|float|null} */
    private function normalizeMetrics(array $snapshot): array
    {
        return [
            'counters' => $this->numericMetrics($snapshot['counters'] ?? []),
            'gauges' => $this->numericMetrics($snapshot['gauges'] ?? []),
            'timings' => $this->timings($snapshot['timings'] ?? []),
            'updated_at' => is_int($snapshot['updated_at'] ?? null) || is_float($snapshot['updated_at'] ?? null)
                ? $snapshot['updated_at'] : null,
        ];
    }

    /** @return array<string,int|float> */
    private function numericMetrics(mixed $metrics): array
    {
        if (!is_array($metrics) || count($metrics) > self::MAX_METRICS_PER_KIND) throw new RuntimeException('telemetry_metrics_invalid');
        $clean = [];
        foreach ($metrics as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,127}$/', $name) !== 1
                || (!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                throw new RuntimeException('telemetry_metric_invalid');
            }
            $clean[$name] = $value;
        }
        ksort($clean);
        return $clean;
    }

    /** @return array<string,array> */
    private function timings(mixed $timings): array
    {
        if (!is_array($timings) || count($timings) > self::MAX_METRICS_PER_KIND) throw new RuntimeException('telemetry_metrics_invalid');
        $clean = [];
        foreach ($timings as $name => $timing) {
            if (!is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,127}$/', $name) !== 1 || !is_array($timing)) {
                throw new RuntimeException('telemetry_metric_invalid');
            }
            $values = [];
            foreach (['count', 'total_ms', 'min_ms', 'max_ms'] as $field) {
                $value = $timing[$field] ?? null;
                if ($value !== null && ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || (float) $value < 0)) {
                    throw new RuntimeException('telemetry_metric_invalid');
                }
                $values[$field] = $value;
            }
            $clean[$name] = $values;
        }
        ksort($clean);
        return $clean;
    }

    private function normalizeTrace(array $record): array
    {
        $level = (string) ($record['level'] ?? '');
        $event = (string) ($record['event'] ?? '');
        if (!in_array($level, ['debug', 'info', 'notice', 'warning', 'error', 'critical'], true)
            || preg_match('/^[a-z][a-z0-9_.:-]{2,127}$/', $event) !== 1) {
            throw new RuntimeException('telemetry_trace_invalid');
        }
        $context = is_array($record['context'] ?? null) ? $record['context'] : [];
        $safeContext = [];
        foreach (self::TRACE_CONTEXT_KEYS as $key) {
            if (!array_key_exists($key, $context)) continue;
            $value = $context[$key];
            if (is_string($value)) $safeContext[$key] = substr($value, 0, 255);
            elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) $safeContext[$key] = $value;
        }
        return [
            'at' => is_int($record['at'] ?? null) || is_float($record['at'] ?? null) ? $record['at'] : null,
            'level' => $level,
            'event' => $event,
            'pid' => is_int($record['pid'] ?? null) ? $record['pid'] : null,
            'context' => $safeContext,
        ];
    }
}
