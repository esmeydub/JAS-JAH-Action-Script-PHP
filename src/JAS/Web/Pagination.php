<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use RuntimeException;

final class Pagination implements Component
{
    private readonly int $pages;
    private readonly Translator $translator;

    public function __construct(
        private readonly Router $router,
        private readonly string $routeName,
        private readonly int $currentPage,
        private readonly int $totalItems,
        private readonly int $perPage,
        private readonly array $routeParameters = [],
        private readonly array $query = [],
        private readonly ?string $label = null,
        ?Translator $translator = null,
    ) {
        if ($currentPage < 1 || $totalItems < 0 || $perPage < 1 || $perPage > 500) throw new RuntimeException('pagination_values_invalid');
        $this->pages = max(1, (int) ceil($totalItems / $perPage));
        if ($currentPage > $this->pages) throw new RuntimeException('pagination_page_out_of_range');
        if ($label !== null && (trim($label) === '' || strlen($label) > 120)) throw new RuntimeException('pagination_label_invalid');
        foreach ($query as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/i', $key) !== 1 || (!is_scalar($value) && $value !== null)) {
                throw new RuntimeException('pagination_query_invalid');
            }
        }
        $this->translator = $translator ?? WebTranslations::translator();
    }

    public function render(): SafeHtml
    {
        $navigationLabel = $this->label ?? $this->translator->text('pagination.summary', ['current' => $this->currentPage, 'total' => $this->pages]);
        if ($this->pages === 1) return Html::element('nav', ['aria-label' => $navigationLabel], Html::element('p', [], $this->translator->text('pagination.summary', ['current' => 1, 'total' => 1])));
        $items = [];
        if ($this->currentPage > 1) $items[] = $this->link($this->translator->text('pagination.previous'), $this->currentPage - 1, 'prev');
        $previous = 0;
        foreach ($this->visiblePages() as $page) {
            if ($previous !== 0 && $page > $previous + 1) $items[] = Html::element('li', ['aria-hidden' => 'true'], '…');
            $items[] = $page === $this->currentPage
                ? Html::element('li', [], Html::element('a', ['href' => $this->url($page), 'aria-current' => 'page', 'aria-label' => $this->translator->text('pagination.page', ['page' => $page])], (string) $page))
                : $this->link((string) $page, $page, null, $this->translator->text('pagination.page', ['page' => $page]));
            $previous = $page;
        }
        if ($this->currentPage < $this->pages) $items[] = $this->link($this->translator->text('pagination.next'), $this->currentPage + 1, 'next');
        return Html::element('nav', ['aria-label' => $navigationLabel], Html::element('ul', ['class' => 'jas-pagination'], $items));
    }

    /** @return list<int> */
    private function visiblePages(): array
    {
        $pages = [1, $this->pages];
        for ($page = max(1, $this->currentPage - 2); $page <= min($this->pages, $this->currentPage + 2); $page++) $pages[] = $page;
        $pages = array_values(array_unique($pages));
        sort($pages);
        return $pages;
    }

    private function link(string $text, int $page, ?string $relation = null, ?string $ariaLabel = null): SafeHtml
    {
        return Html::element('li', [], Html::element('a', [
            'href' => $this->url($page),
            'rel' => $relation,
            'aria-label' => $ariaLabel,
        ], $text));
    }

    private function url(int $page): string
    {
        return $this->router->url($this->routeName, $this->routeParameters, array_merge($this->query, ['page' => $page]));
    }
}
