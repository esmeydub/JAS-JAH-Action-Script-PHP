<?php

declare(strict_types=1);

namespace Jah\JAS\Server;

use Jah\JAS\Transport\FrameProtocol;
use RuntimeException;

final class PersistentTcpServer
{
    private bool $running = false;

    public function __construct(
        private readonly string $endpoint,
        private readonly FrameProtocol $frames,
        private readonly mixed $handler,
        private readonly array $tls = []
    ) {
        if (!is_callable($handler)) {
            throw new RuntimeException('server_handler_invalid');
        }
    }

    public function run(int $maxRequests = 0): void
    {
        $endpoint = $this->normalizedEndpoint();
        $context = stream_context_create($this->contextOptions());
        $server = @stream_socket_server(
            $endpoint,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );
        if (!$server) {
            throw new RuntimeException('tcp_server_failed:' . $errstr);
        }

        stream_set_blocking($server, false);
        $this->running = true;
        $clients = [];
        $served = 0;

        try {
            while ($this->running) {
                $read = array_merge([$server], array_values($clients));
                $write = $except = [];
                $ready = @stream_select($read, $write, $except, 1);
                if ($ready === false) {
                    throw new RuntimeException('tcp_select_failed');
                }
                if ($ready === 0) {
                    continue;
                }

                foreach ($read as $socket) {
                    if ($socket === $server) {
                        $client = @stream_socket_accept($server, 0);
                        if ($client !== false) {
                            stream_set_blocking($client, true);
                            stream_set_timeout($client, 10);
                            $clients[(int) $client] = $client;
                        }
                        continue;
                    }

                    try {
                        $payload = $this->frames->read($socket);
                        $reply = ($this->handler)($payload, $socket);
                        $this->frames->write($socket, (string) $reply);
                        $served++;
                    } catch (\Throwable $e) {
                        $error = 'JAS_SERVER_ERROR:' . $e->getMessage();
                        try {
                            $this->frames->write($socket, $error);
                        } catch (\Throwable) {
                            // The peer may already have closed the connection.
                        }
                    } finally {
                        @fclose($socket);
                        unset($clients[(int) $socket]);
                    }

                    if ($maxRequests > 0 && $served >= $maxRequests) {
                        $this->running = false;
                        break;
                    }
                }
            }
        } finally {
            foreach ($clients as $client) {
                @fclose($client);
            }
            @fclose($server);
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function normalizedEndpoint(): string
    {
        if (($this->tls['enabled'] ?? false) !== true) {
            return $this->endpoint;
        }
        if (str_starts_with($this->endpoint, 'tls://') || str_starts_with($this->endpoint, 'ssl://')) {
            return $this->endpoint;
        }
        if (str_starts_with($this->endpoint, 'tcp://')) {
            return 'tls://' . substr($this->endpoint, 6);
        }
        throw new RuntimeException('tls_endpoint_invalid');
    }

    private function contextOptions(): array
    {
        if (($this->tls['enabled'] ?? false) !== true) {
            return [];
        }

        $cert = (string) ($this->tls['cert'] ?? '');
        $key = (string) ($this->tls['key'] ?? '');
        if ($cert === '' || $key === '' || !is_file($cert) || !is_file($key)) {
            throw new RuntimeException('tls_cert_key_required');
        }

        return [
            'ssl' => [
                'local_cert' => $cert,
                'local_pk' => $key,
                'passphrase' => (string) ($this->tls['passphrase'] ?? ''),
                'verify_peer' => (bool) ($this->tls['verify_peer'] ?? false),
                'allow_self_signed' => (bool) ($this->tls['allow_self_signed'] ?? false),
                'crypto_method' => STREAM_CRYPTO_METHOD_TLS_SERVER,
                'disable_compression' => true,
            ],
        ];
    }
}
