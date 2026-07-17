<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use RuntimeException;

final class LocaleNegotiator
{
    /** @param list<string> $allowed */
    public function __construct(private readonly array $allowed, private readonly string $fallback)
    {
        if (!array_is_list($allowed) || $allowed === [] || count($allowed) > 32 || !in_array($fallback, $allowed, true)) {
            throw new RuntimeException('locale_allowlist_invalid');
        }
        foreach ($allowed as $locale) if (!is_string($locale) || !self::valid($locale)) throw new RuntimeException('locale_allowlist_invalid');
    }

    public function negotiate(?string $acceptLanguage): string
    {
        if ($acceptLanguage === null || trim($acceptLanguage) === '') return $this->fallback;
        if (strlen($acceptLanguage) > 1_024 || preg_match('/[^A-Za-z0-9,;=*.\-_ ]/', $acceptLanguage)) return $this->fallback;
        $preferences = [];
        foreach (explode(',', $acceptLanguage) as $order => $part) {
            $pieces = array_map('trim', explode(';', $part));
            $tag = $pieces[0] ?? '';
            $quality = 1.0;
            foreach (array_slice($pieces, 1) as $parameter) {
                if (preg_match('/^q=(0(?:\.\d{1,3})?|1(?:\.0{1,3})?)$/', $parameter, $match) === 1) $quality = (float) $match[1];
                else $quality = 0.0;
            }
            if ($quality > 0) $preferences[] = ['tag' => $tag, 'quality' => $quality, 'order' => $order];
        }
        usort($preferences, static fn(array $a, array $b): int => $b['quality'] <=> $a['quality'] ?: $a['order'] <=> $b['order']);
        foreach ($preferences as $preference) {
            if ($preference['tag'] === '*') return $this->fallback;
            foreach ($this->allowed as $locale) if (strcasecmp($locale, $preference['tag']) === 0) return $locale;
            $language = strtolower(explode('-', $preference['tag'], 2)[0]);
            foreach ($this->allowed as $locale) if (strtolower(explode('-', $locale, 2)[0]) === $language) return $locale;
        }
        return $this->fallback;
    }

    public static function valid(string $locale): bool
    {
        return preg_match('/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-(?:[A-Z]{2}|[0-9]{3}))?$/', $locale) === 1;
    }
}
