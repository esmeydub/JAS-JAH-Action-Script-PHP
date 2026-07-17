<?php

declare(strict_types=1);

namespace Jah\JAS\Server;

use Jah\JAS\Transport\FrameProtocol;
use RuntimeException;

final class TcpClusterClient
{
    public function __construct(
        private readonly FrameProtocol $frames,
        private readonly array $tls = []
    ) {}

    public function request(string $endpoint, string $payload, float $timeout = 3.0): string
    {
        $target = $this->normalizedEndpoint($endpoint);
        $context = stream_context_create($this->contextOptions());
        $socket = @stream_socket_client(
            $target,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$socket) {
            throw new RuntimeException('cluster_connect_failed:' . $errstr);
        }

        $seconds = max(1, (int) floor($timeout));
        $microseconds = max(0, (int) (($timeout - floor($timeout)) * 1_000_000));
        stream_set_timeout($socket, $seconds, $microseconds);

        try {
            $this->frames->write($socket, $payload);
            $reply = $this->frames->read($socket);
            $meta = stream_get_meta_data($socket);
            if ($meta['timed_out'] === true) {
                throw new RuntimeException('cluster_request_timeout');
            }
            return $reply;
        } finally {
            fclose($socket);
        }
    }

    private function normalizedEndpoint(string $endpoint): string
    {
        if (($this->tls['enabled'] ?? false) !== true) {
            return $endpoint;
        }
        if (str_starts_with($endpoint, 'tls://') || str_starts_with($endpoint, 'ssl://')) {
            return $endpoint;
        }
        if (str_starts_with($endpoint, 'tcp://')) {
            return 'tls://' . substr($endpoint, 6);
        }
        throw new RuntimeException('tls_endpoint_invalid');
    }

    private function contextOptions(): array
    {
        if (($this->tls['enabled'] ?? false) !== true) {
            return [];
        }

        $ssl = [
            'verify_peer' => (bool) ($this->tls['verify_peer'] ?? true),
            'verify_peer_name' => (bool) ($this->tls['verify_peer_name'] ?? true),
            'allow_self_signed' => (bool) ($this->tls['allow_self_signed'] ?? false),
            'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
            'disable_compression' => true,
        ];
        $ca = (string) ($this->tls['ca'] ?? '');
        $peerName = (string) ($this->tls['peer_name'] ?? '');
        if ($ca !== '') {
            if (!is_file($ca)) {
                throw new RuntimeException('tls_ca_invalid');
            }
            $ssl['cafile'] = $ca;
        }
        if ($peerName !== '') {
            $ssl['peer_name'] = $peerName;
        }
        return ['ssl' => $ssl];
    }
}
