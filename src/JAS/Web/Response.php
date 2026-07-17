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
            $values = is_array($value) && array_is_list($value) ? $value : [$value];
            $validValues = $values !== [];
            foreach ($values as $headerValue) {
                if (!is_string($headerValue) || preg_match('/[\r\n]/', $headerValue)) $validValues = false;
            }
            if (!is_string($name) || preg_match('/^[A-Za-z0-9-]{1,64}$/', $name) !== 1 || !$validValues) {
                throw new InvalidArgumentException('http_header_invalid');
            }
        }
    }

    public static function html(SafeHtml|Component $content, int $status = 200): self
    {
        $html = $content instanceof Component ? $content->render() : $content;
        return new self($html->value(), $status, 'text/html; charset=utf-8');
    }

    public static function error(int $status, ?string $requestId = null, string $homeUrl = '/', ?Translator $translator = null): self
    {
        $translator ??= WebTranslations::translator();
        $error = new ErrorPage($status, $requestId, $homeUrl, $translator);
        return self::html(new Page($error->title(), $error, $translator->locale()), $status);
    }

    public function withCookie(Cookie $cookie): self
    {
        $headers = $this->headers;
        $existing = $headers['Set-Cookie'] ?? [];
        if (is_string($existing)) $existing = [$existing];
        $headers['Set-Cookie'] = [...$existing, $cookie->header()];
        return new self($this->body, $this->status, $this->contentType, $headers);
    }

    public function send(): never
    {
        http_response_code($this->status);
        header('Content-Type: ' . $this->contentType);
        foreach ($this->headers as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $headerValue) header($name . ': ' . $headerValue, false);
        }
        echo $this->body;
        exit;
    }
}
