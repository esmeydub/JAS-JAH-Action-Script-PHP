<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\DataCore\PhpSerializer;
use Jah\JAS\Observability\JasbTelemetryExporter;
use Jah\JAS\Observability\TelemetryAdapter;
use Jah\JAS\Protocol\JasBinaryCodec;
use Jah\JAS\Protocol\Opcodes;
use Jah\JAS\Security\SalkPacketGuard;

$key = str_repeat('T', 32);
$codec = new JasBinaryCodec(new SalkPacketGuard($key));
$adapter = new class implements TelemetryAdapter {
    /** @var list<string> */
    public array $packets = [];
    public function send(string $packet): void { $this->packets[] = $packet; }
};
$exporter = new JasbTelemetryExporter($codec, $adapter);
$metricsReport = $exporter->metrics([
    'counters' => ['requests.total' => 12],
    'gauges' => ['queue.depth' => 3],
    'timings' => ['http.duration' => ['count' => 2, 'total_ms' => 30.0, 'min_ms' => 10.0, 'max_ms' => 20.0]],
    'updated_at' => 1_800_000_000.5,
], 'node-gobierno-1');
if ($metricsReport['opcode'] !== Opcodes::TELEMETRY_METRICS || $metricsReport['count'] !== 3 || count($adapter->packets) !== 1) {
    throw new RuntimeException('telemetry_metrics_export_failed');
}
$metricsPacket = $codec->decode($adapter->packets[0]);
$metricsPayload = PhpSerializer::decode($metricsPacket->payload);
if ($metricsPacket->opcode !== Opcodes::TELEMETRY_METRICS || ($metricsPayload['schema'] ?? null) !== 'JAS_TELEMETRY_V1'
    || ($metricsPayload['metrics']['counters']['requests.total'] ?? null) !== 12) throw new RuntimeException('telemetry_metrics_packet_invalid');

$traceReport = $exporter->traces([[
    'at' => 1_800_000_001.0,
    'level' => 'info',
    'event' => 'http.request',
    'pid' => 123,
    'context' => [
        'request_id' => 'req-1', 'method' => 'GET', 'path' => '/ciudadanos/SECRET',
        'status' => 200, 'duration_ms' => 12.5, 'user_id' => 'PERSON-SECRET',
        'authorization' => 'Bearer secret', 'password' => 'secret',
    ],
]], 'node-gobierno-1');
if ($traceReport['opcode'] !== Opcodes::TELEMETRY_TRACES || $traceReport['count'] !== 1 || count($adapter->packets) !== 2) {
    throw new RuntimeException('telemetry_trace_export_failed');
}
$tracePayload = PhpSerializer::decode($codec->decode($adapter->packets[1])->payload);
$context = $tracePayload['traces'][0]['context'] ?? [];
if (($context['request_id'] ?? null) !== 'req-1' || isset($context['path'], $context['user_id'], $context['authorization'], $context['password'])) {
    throw new RuntimeException('telemetry_trace_redaction_failed');
}

$tampered = $adapter->packets[0];
$tampered[40] = chr(ord($tampered[40]) ^ 1);
try {
    $codec->decode($tampered);
    throw new RuntimeException('telemetry_tamper_accepted');
} catch (InvalidArgumentException $error) {
    if ($error->getMessage() !== 'Firma SALK inválida') throw $error;
}

try {
    $exporter->metrics(['counters' => ['invalid metric' => 1]], 'node-gobierno-1');
    throw new RuntimeException('telemetry_invalid_metric_accepted');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'telemetry_metric_invalid') throw $error;
}
$failing = new class implements TelemetryAdapter {
    public function send(string $packet): void { throw new RuntimeException('remote-secret-error'); }
};
try {
    (new JasbTelemetryExporter($codec, $failing))->traces([], 'node-gobierno-1');
    throw new RuntimeException('telemetry_adapter_failure_ignored');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'telemetry_adapter_failed') throw $error;
}

echo "JAS TELEMETRY EXPORT: PASS\n";
