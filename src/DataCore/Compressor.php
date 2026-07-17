<?php

declare(strict_types=1);

namespace Jah\DataCore;

/**
 * Compressor - Compresión LZ4/ZSTD para archivos
 */
final class Compressor
{
    public static function compress(string $data, string $algo = 'lz4'): string
    {
        return match ($algo) {
            'lz4' => function_exists('lzcompress')
                ? lzcompress($data)
                : self::phpCompact($data),
            'zstd' => function_exists('zstd_compress')
                ? zstd_compress($data)
                : self::phpCompact($data),
            'gzip' => self::gzipEncode($data),
            default => $data,
        };
    }

    public static function decompress(string $data, string $algo = 'lz4'): string
    {
        return match ($algo) {
            'lz4' => function_exists('lzuncompress')
                ? lzuncompress($data)
                : $data,
            'zstd' => function_exists('zstd_decompress')
                ? zstd_decompress($data)
                : $data,
            'gzip' => self::gzipDecode($data),
            default => $data,
        };
    }

    private static function phpCompact(string $payload): string
    {
        // Compacta payload serializado PHP si es posible
        $decoded = PhpSerializer::decode($payload);
        return $decoded === null ? $payload : PhpSerializer::encode($decoded);
    }

    private static function gzipEncode(string $data): string
    {
        $encoded = gzencode($data);
        if ($encoded === false) throw new \RuntimeException('gzip compression failed');
        return $encoded;
    }

    private static function gzipDecode(string $data): string
    {
        $decoded = gzdecode($data);
        if ($decoded === false) throw new \RuntimeException('gzip decompression failed');
        return $decoded;
    }

    public static function compressFile(string $input, string $output, string $algo = 'gzip'): bool
    {
        $content = file_get_contents($input);
        if ($content === false) {
            return false;
        }

        $compressed = self::compress($content, $algo);
        $bytes = file_put_contents($output, $compressed);

        return $bytes !== false;
    }

    public static function decompressFile(string $input, string $output, string $algo = 'gzip'): bool
    {
        $content = file_get_contents($input);
        if ($content === false) {
            return false;
        }

        $decompressed = self::decompress($content, $algo);
        $bytes = file_put_contents($output, $decompressed);

        return $bytes !== false;
    }
}
