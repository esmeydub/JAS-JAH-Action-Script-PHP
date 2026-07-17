<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use InvalidArgumentException;

final class Page implements Component
{
    public function __construct(private readonly string $title, private readonly Component|SafeHtml $content, private readonly string $language = 'es')
    {
        if ($title === '' || strlen($title) > 160) throw new InvalidArgumentException('page_title_invalid');
        if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $language)) throw new InvalidArgumentException('page_language_invalid');
    }

    public function render(): SafeHtml
    {
        return Html::fragment(
            new SafeHtml('<!doctype html>'),
            Html::element('html', ['lang' => $this->language],
                Html::element('head', [],
                    Html::element('meta', ['charset' => 'utf-8']),
                    Html::element('meta', ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1']),
                    Html::element('title', [], $this->title)
                ),
                Html::element('body', [], $this->content)
            )
        );
    }
}
