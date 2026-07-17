<?php

declare(strict_types=1);

namespace Jah\JAS\Transport;

use RuntimeException;

final class FrameProtocol
{
    public function __construct(private readonly int $maxFrameBytes = 16_777_216)
    {
        if ($maxFrameBytes < 1024) {
            throw new RuntimeException('frame_limit_invalid');
        }
    }

    public function encode(string $payload): string
    {
        if (strlen($payload) > $this->maxFrameBytes) {
            throw new RuntimeException('frame_too_large');
        }

        return pack('N', strlen($payload)) . $payload;
    }

    /** @param resource $stream */
    public function write(mixed $stream, string $payload): void
    {
        if (!is_resource($stream)) {
            throw new RuntimeException('frame_stream_invalid');
        }

        $frame = $this->encode($payload);
        $length = strlen($frame);
        $offset = 0;
        while ($offset < $length) {
            $written = @fwrite($stream, substr($frame, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('frame_write_failed');
            }
            $offset += $written;
        }

        if (!@fflush($stream)) {
            throw new RuntimeException('frame_flush_failed');
        }
    }

    /** @param resource $stream */
    public function read(mixed $stream): string
    {
        if (!is_resource($stream)) {
            throw new RuntimeException('frame_stream_invalid');
        }

        $header = $this->readExact($stream, 4);
        $unpacked = unpack('Nlength', $header);
        $length = (int) ($unpacked['length'] ?? -1);
        if ($length < 0 || $length > $this->maxFrameBytes) {
            throw new RuntimeException('frame_too_large');
        }

        return $this->readExact($stream, $length);
    }

    /** @param resource $stream */
    private function readExact(mixed $stream, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = @fread($stream, $length - strlen($data));
            if ($chunk === false || ($chunk === '' && feof($stream))) {
                throw new RuntimeException('frame_truncated');
            }
            if ($chunk === '') {
                $metadata = stream_get_meta_data($stream);
                if ($metadata['timed_out'] === true) {
                    throw new RuntimeException('frame_read_timeout');
                }
                usleep(1_000);
                continue;
            }
            $data .= $chunk;
        }

        return $data;
    }
}
