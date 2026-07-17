<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use RuntimeException;

final class Layout implements Component
{
    /** @var array<string,Component|SafeHtml|string> */
    private array $slots = [];
    private readonly Translator $translator;

    public function __construct(private readonly ?string $navigationLabel = null, ?Translator $translator = null)
    {
        if ($navigationLabel !== null && (trim($navigationLabel) === '' || strlen($navigationLabel) > 120)) throw new RuntimeException('layout_navigation_label_invalid');
        $this->translator = $translator ?? WebTranslations::translator();
    }

    public function slot(string $name, Component|SafeHtml|string $content): self
    {
        if (!in_array($name, ['header', 'navigation', 'main', 'aside', 'footer'], true)) throw new RuntimeException('layout_slot_invalid');
        if (isset($this->slots[$name])) throw new RuntimeException('layout_slot_duplicated');
        $this->slots[$name] = $content;
        return $this;
    }

    public function render(): SafeHtml
    {
        if (!isset($this->slots['main'])) throw new RuntimeException('layout_main_required');
        return Html::fragment(
            Html::element('a', ['class' => 'jas-skip-link', 'href' => '#jas-main'], $this->translator->text('layout.skip')),
            isset($this->slots['header']) ? Html::element('header', [], $this->slots['header']) : null,
            isset($this->slots['navigation']) ? Html::element('nav', ['aria-label' => $this->navigationLabel ?? $this->translator->text('layout.navigation')], $this->slots['navigation']) : null,
            Html::element('main', ['id' => 'jas-main', 'tabindex' => '-1'], $this->slots['main']),
            isset($this->slots['aside']) ? Html::element('aside', [], $this->slots['aside']) : null,
            isset($this->slots['footer']) ? Html::element('footer', [], $this->slots['footer']) : null,
        );
    }
}
