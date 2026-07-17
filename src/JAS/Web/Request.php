<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use InvalidArgumentException;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $input = [],
        public readonly array $headers = [],
        public readonly ?string $requestId = null,
        public readonly array $attributes = []
    ) {
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) throw new InvalidArgumentException('http_method_invalid');
        if (!preg_match('#^/[A-Za-z0-9/_-]{0,255}$#', $path) || str_contains($path, '..')) throw new InvalidArgumentException('http_path_invalid');
        if (count($input) > 1_000) throw new InvalidArgumentException('http_input_too_large');
        if ($requestId !== null && ($requestId === '' || strlen($requestId) > 255)) throw new InvalidArgumentException('http_request_id_invalid');
    }

    public static function fromGlobals(int $maxBytes = 1_048_576): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > $maxBytes) throw new InvalidArgumentException('http_payload_too_large');
        $input = $method === 'GET' ? $_GET : $_POST;
        if (strlen(http_build_query($input)) > $maxBytes) throw new InvalidArgumentException('http_payload_too_large');
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_scalar($value)) $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
        }
        $requestId = trim((string) ($headers['x-jas-request-id'] ?? '')) ?: null;
        return new self($method, $path, $input, $headers, $requestId);
    }
}
