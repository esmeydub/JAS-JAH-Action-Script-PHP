<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use InvalidArgumentException;

final class Response
{
    public function __construct(
        public readonly string $body,
        public readonly int $status = 200,
        public readonly string $contentType = 'text/plain; charset=utf-8',
        public readonly array $headers = []
    ) {
        if ($status < 100 || $status > 599) throw new InvalidArgumentException('http_status_invalid');
        if (!in_array($contentType, ['text/plain; charset=utf-8', 'text/html; charset=utf-8'], true)) throw new InvalidArgumentException('http_content_type_invalid');
        foreach ($headers as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z0-9-]{1,64}$/', $name) !== 1 || !is_string($value) || preg_match('/[\r\n]/', $value)) {
                throw new InvalidArgumentException('http_header_invalid');
            }
        }
    }

    public static function html(SafeHtml|Component $content, int $status = 200): self
    {
        $html = $content instanceof Component ? $content->render() : $content;
        return new self($html->value(), $status, 'text/html; charset=utf-8');
    }

    public function send(): never
    {
        http_response_code($this->status);
        header('Content-Type: ' . $this->contentType);
        foreach ($this->headers as $name => $value) header($name . ': ' . $value);
        echo $this->body;
        exit;
    }
}
