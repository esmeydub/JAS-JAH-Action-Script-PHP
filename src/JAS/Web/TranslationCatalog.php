<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use Jah\JAS\Type\TypeRegistry;
use RuntimeException;

final class TranslationCatalog
{
    /** @var array<string,array{template:string,parameters:array<string,string>}> */
    private array $messages = [];
    private TypeRegistry $types;

    public function __construct(public readonly string $locale)
    {
        if (!LocaleNegotiator::valid($locale)) throw new RuntimeException('translation_locale_invalid');
        $this->types = new TypeRegistry();
    }

    /** @param array<string,string> $parameters */
    public function message(string $key, string $template, array $parameters = []): self
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,127}$/', $key) || isset($this->messages[$key])) throw new RuntimeException('translation_key_invalid');
        if ($template === '' || strlen($template) > 4_096 || str_contains($template, "\0")) throw new RuntimeException('translation_template_invalid');
        if (!array_is_list($parameters) && $parameters !== []) {
            foreach ($parameters as $name => $type) {
                if (!is_string($name) || preg_match('/^[a-z_][a-z0-9_]{0,63}$/', $name) !== 1
                    || !is_string($type) || !in_array($type, ['string', 'non-empty-string', 'identifier', 'int', 'positive-int', 'non-negative-int', 'float', 'number', 'bool', 'date', 'datetime', 'timezone'], true)) {
                    throw new RuntimeException('translation_parameters_invalid');
                }
            }
        } elseif ($parameters !== []) {
            throw new RuntimeException('translation_parameters_invalid');
        }
        preg_match_all('/\{([a-z_][a-z0-9_]*)\}/', $template, $matches);
        $placeholders = array_values(array_unique($matches[1] ?? []));
        sort($placeholders);
        $defined = array_keys($parameters);
        sort($defined);
        if ($placeholders !== $defined) throw new RuntimeException('translation_placeholders_mismatch');
        $withoutPlaceholders = preg_replace('/\{[a-z_][a-z0-9_]*\}/', '', $template);
        if (!is_string($withoutPlaceholders) || str_contains($withoutPlaceholders, '{') || str_contains($withoutPlaceholders, '}')) {
            throw new RuntimeException('translation_template_invalid');
        }
        ksort($parameters);
        $this->messages[$key] = ['template' => $template, 'parameters' => $parameters];
        return $this;
    }

    public function has(string $key): bool { return isset($this->messages[$key]); }

    /** @return array{template:string,parameters:array<string,string>} */
    public function definition(string $key): array
    {
        return $this->messages[$key] ?? throw new RuntimeException('translation_missing');
    }

    /** @return list<string> */
    public function keys(): array { return array_keys($this->messages); }

    public function render(string $key, array $parameters = []): string
    {
        $message = $this->definition($key);
        $expected = array_keys($message['parameters']);
        $provided = array_keys($parameters);
        sort($expected); sort($provided);
        if ($expected !== $provided) throw new RuntimeException('translation_arguments_mismatch');
        $replace = [];
        foreach ($message['parameters'] as $name => $type) {
            $value = $parameters[$name];
            if (!$this->types->validate($type, $value) || (!is_scalar($value) && $value !== null)) {
                throw new RuntimeException('translation_argument_type_invalid');
            }
            $replace['{' . $name . '}'] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }
        return strtr($message['template'], $replace);
    }
}
