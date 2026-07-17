<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use InvalidArgumentException;
use RuntimeException;

final class Response
{
    private readonly mixed $stream;
    private bool $streamConsumed = false;

    public function __construct(
        public readonly string $body,
        public readonly int $status = 200,
        public readonly string $contentType = 'text/plain; charset=utf-8',
        public readonly array $headers = [],
        ?callable $stream = null,
    ) {
        if ($status < 100 || $status > 599) throw new InvalidArgumentException('http_status_invalid');
        if (preg_match('#^[a-z0-9][a-z0-9.+-]{0,63}/[a-z0-9][a-z0-9.+-]{0,63}(?:; charset=utf-8)?$#', $contentType) !== 1) throw new InvalidArgumentException('http_content_type_invalid');
        if ($stream !== null && $body !== '') throw new InvalidArgumentException('http_stream_body_conflict');
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
        $this->stream = $stream;
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

    /** @param callable(callable(string):void):void $producer */
    public static function stream(callable $producer, string $contentType = 'application/octet-stream', int $status = 200, array $headers = []): self
    {
        return new self('', $status, $contentType, $headers, $producer);
    }

    public function isStreamed(): bool { return $this->stream !== null; }

    public function withHeaders(array $headers): self
    {
        return new self($this->body, $this->status, $this->contentType, $this->headers + $headers, $this->stream);
    }

    public function withCookie(Cookie $cookie): self
    {
        $headers = $this->headers;
        $existing = $headers['Set-Cookie'] ?? [];
        if (is_string($existing)) $existing = [$existing];
        $headers['Set-Cookie'] = [...$existing, $cookie->header()];
        return new self($this->body, $this->status, $this->contentType, $headers, $this->stream);
    }

    /** @param callable(string):void $writer */
    public function emit(callable $writer): void
    {
        if ($this->stream === null) { $writer($this->body); return; }
        if ($this->streamConsumed) throw new RuntimeException('response_stream_already_consumed');
        $this->streamConsumed = true;
        ($this->stream)(static function (string $chunk) use ($writer): void {
            if ($chunk === '') return;
            $writer($chunk);
        });
    }

    public function send(): never
    {
        http_response_code($this->status);
        header('Content-Type: ' . $this->contentType);
        foreach ($this->headers as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $headerValue) header($name . ': ' . $headerValue, false);
        }
        $this->emit(static function (string $chunk): void { echo $chunk; });
        exit;
    }
}
