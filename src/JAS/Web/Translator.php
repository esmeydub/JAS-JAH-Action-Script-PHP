<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use RuntimeException;

final class Translator
{
    /** @var array<string,TranslationCatalog> */
    private array $catalogs = [];
    private string $locale;

    public function __construct(private readonly TranslationCatalog $fallback)
    {
        $this->catalogs[$fallback->locale] = $fallback;
        $this->locale = $fallback->locale;
    }

    public function add(TranslationCatalog $catalog): self
    {
        if (isset($this->catalogs[$catalog->locale])) throw new RuntimeException('translation_catalog_duplicated');
        foreach ($catalog->keys() as $key) {
            if (!$this->fallback->has($key)) throw new RuntimeException('translation_unknown_key');
            if ($catalog->definition($key)['parameters'] !== $this->fallback->definition($key)['parameters']) {
                throw new RuntimeException('translation_schema_mismatch');
            }
        }
        $this->catalogs[$catalog->locale] = $catalog;
        return $this;
    }

    public function forLocale(string $locale): self
    {
        if (!isset($this->catalogs[$locale])) throw new RuntimeException('translation_locale_not_allowed');
        $translator = clone $this;
        $translator->locale = $locale;
        return $translator;
    }

    public function locale(): string { return $this->locale; }

    /** @return list<string> */
    public function locales(): array { return array_keys($this->catalogs); }

    public function text(string $key, array $parameters = []): string
    {
        $catalog = $this->catalogs[$this->locale];
        return ($catalog->has($key) ? $catalog : $this->fallback)->render($key, $parameters);
    }

    public function html(string $key, array $parameters = []): SafeHtml
    {
        return Html::text($this->text($key, $parameters));
    }
}
