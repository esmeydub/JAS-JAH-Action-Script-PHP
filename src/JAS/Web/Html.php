<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use InvalidArgumentException;

final class Html
{
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    public static function text(string|int|float|bool|null $value): SafeHtml
    {
        return new SafeHtml(htmlspecialchars(self::scalar($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    public static function element(string $tag, array $attributes = [], mixed ...$children): SafeHtml
    {
        if (!preg_match('/^[a-z][a-z0-9-]{0,31}$/', $tag) || in_array($tag, ['script', 'iframe', 'object', 'embed'], true)) {
            throw new InvalidArgumentException('html_tag_not_allowed');
        }
        $html = '<' . $tag . self::attributes($attributes) . '>';
        if (in_array($tag, self::VOID, true)) {
            if ($children !== []) throw new InvalidArgumentException('html_void_element_children');
            return new SafeHtml($html);
        }
        foreach ($children as $child) $html .= self::child($child);
        return new SafeHtml($html . '</' . $tag . '>');
    }

    public static function fragment(mixed ...$children): SafeHtml
    {
        $html = '';
        foreach ($children as $child) $html .= self::child($child);
        return new SafeHtml($html);
    }

    private static function attributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $name => $value) {
            $attributePattern = '/^('
                . 'class|id|title|name|value|type|method|action|href|src|alt|role|for|lang|charset|content|'
                . 'disabled|required|checked|selected|readonly|multiple|placeholder|autocomplete|'
                . 'scope|colspan|rowspan|tabindex|rel|min|max|step|accept|enctype|'
                . 'aria-[a-z0-9-]+|data-[a-z0-9-]+'
                . ')$/';
            $allowedName = is_string($name) && preg_match($attributePattern, $name);
            if (!$allowedName) {
                throw new InvalidArgumentException('html_attribute_not_allowed');
            }
            if (str_starts_with($name, 'on')) throw new InvalidArgumentException('html_event_attribute_forbidden');
            if (is_bool($value)) { if ($value) $html .= ' ' . $name; continue; }
            if ($value === null) continue;
            if (!is_scalar($value)) throw new InvalidArgumentException('html_attribute_value_invalid');
            $text = (string) $value;
            if (in_array($name, ['href', 'src', 'action'], true) && preg_match('/^\s*(javascript|data):/i', $text)) {
                throw new InvalidArgumentException('html_url_scheme_forbidden');
            }
            $html .= ' ' . $name . '="' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $html;
    }

    private static function child(mixed $child): string
    {
        if ($child instanceof Component) return $child->render()->value();
        if ($child instanceof SafeHtml) return $child->value();
        if (is_array($child)) {
            $html = '';
            foreach ($child as $item) $html .= self::child($item);
            return $html;
        }
        if (is_string($child) || is_int($child) || is_float($child) || is_bool($child) || $child === null) {
            return self::text($child)->value();
        }
        throw new InvalidArgumentException('html_child_invalid');
    }

    private static function scalar(string|int|float|bool|null $value): string
    {
        if ($value === null) return '';
        if (is_bool($value)) return $value ? 'true' : 'false';
        return (string) $value;
    }
}
