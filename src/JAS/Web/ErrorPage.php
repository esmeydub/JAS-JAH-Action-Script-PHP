<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use RuntimeException;

final class ErrorPage implements Component
{
    private const STATUSES = [400, 401, 403, 404, 409, 422, 429, 500, 503];
    private readonly Translator $translator;

    public function __construct(
        public readonly int $status,
        private readonly ?string $requestId = null,
        private readonly string $homeUrl = '/',
        ?Translator $translator = null,
    ) {
        if (!in_array($status, self::STATUSES, true)) throw new RuntimeException('error_page_status_invalid');
        if ($requestId !== null && ($requestId === '' || strlen($requestId) > 255)) throw new RuntimeException('error_page_request_id_invalid');
        if (!str_starts_with($homeUrl, '/') || str_starts_with($homeUrl, '//') || str_contains($homeUrl, "\r") || str_contains($homeUrl, "\n")) {
            throw new RuntimeException('error_page_home_url_invalid');
        }
        $this->translator = $translator ?? WebTranslations::translator();
    }

    public function title(): string { return $this->translator->text('error.' . $this->status . '.title'); }

    public function render(): SafeHtml
    {
        return Html::element('main', ['id' => 'jas-main', 'tabindex' => '-1'],
            Html::element('h1', [], $this->title()),
            Html::element('p', [], $this->translator->text('error.' . $this->status . '.message')),
            $this->requestId !== null ? Html::element('p', [], $this->translator->text('error.reference', ['request_id' => $this->requestId])) : null,
            Html::element('a', ['href' => $this->homeUrl], $this->translator->text('error.home')),
        );
    }
}
