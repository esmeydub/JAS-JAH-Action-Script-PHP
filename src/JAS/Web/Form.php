<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use Jah\JAS\Type\TypeRegistry;
use InvalidArgumentException;

final class Form implements Component
{
    private array $values = [];
    private array $errors = [];

    public function __construct(
        private readonly TypeRegistry $types,
        private readonly string $type,
        private readonly string $action,
        private readonly string $csrfToken,
        private readonly array $labels = [],
        private readonly string $method = 'POST'
    ) {
        if (!$types->has($type)) throw new InvalidArgumentException('form_type_not_defined');
        if (!in_array($method, ['POST', 'GET'], true)) throw new InvalidArgumentException('form_method_invalid');
        if (!str_starts_with($action, '/') || str_contains($action, '..')) throw new InvalidArgumentException('form_action_invalid');
        if (strlen($csrfToken) < 32) throw new InvalidArgumentException('form_csrf_invalid');
    }

    /** @return array{valid:bool,data:array,errors:array<string,string>} */
    public function submit(array $input): array
    {
        $this->values = $input; $this->errors = []; $data = [];
        foreach ($this->types->definition($this->type)['fields'] as $field => $expression) {
            $optional = str_ends_with($field, '?'); $name = $optional ? substr($field, 0, -1) : $field;
            if (!array_key_exists($name, $input) || $input[$name] === '') {
                if (!$optional) $this->errors[$name] = 'required';
                continue;
            }
            try { $data[$name] = $this->coerce((string) $expression, $input[$name]); }
            catch (InvalidArgumentException) { $this->errors[$name] = 'invalid_type'; }
        }
        $allowed = array_map(static fn(string $field): string => rtrim($field, '?'), array_keys($this->types->definition($this->type)['fields']));
        foreach (array_diff(array_keys($input), [...$allowed, '_csrf']) as $unknown) $this->errors[(string) $unknown] = 'unknown_field';
        if ($this->errors === [] && !$this->types->validate($this->type, $data)) $this->errors['_form'] = 'contract_mismatch';
        return ['valid' => $this->errors === [], 'data' => $data, 'errors' => $this->errors];
    }

    public function render(): SafeHtml
    {
        $children = [Html::element('input', ['type' => 'hidden', 'name' => '_csrf', 'value' => $this->csrfToken])];
        foreach ($this->types->definition($this->type)['fields'] as $field => $expression) {
            $optional = str_ends_with($field, '?'); $name = $optional ? substr($field, 0, -1) : $field;
            $id = 'jas-field-' . $name;
            $inputType = in_array($expression, ['int', 'integer', 'positive-int', 'non-negative-int', 'number', 'float'], true) ? 'number' : 'text';
            $children[] = Html::element('div', ['class' => 'jas-field'],
                Html::element('label', ['for' => $id], $this->labels[$name] ?? ucfirst(str_replace('_', ' ', $name))),
                Html::element('input', [
                    'id' => $id,
                    'name' => $name,
                    'type' => $inputType,
                    'value' => (string) ($this->values[$name] ?? ''),
                    'required' => !$optional,
                    'aria-invalid' => isset($this->errors[$name]) ? 'true' : 'false',
                ]),
                isset($this->errors[$name]) ? Html::element('span', ['role' => 'alert'], $this->errors[$name]) : null
            );
        }
        $children[] = Html::element('button', ['type' => 'submit'], 'Enviar');
        return Html::element('form', ['method' => $this->method, 'action' => $this->action], $children);
    }

    private function coerce(string $type, mixed $value): mixed
    {
        if (!is_scalar($value)) throw new InvalidArgumentException('form_value_invalid');
        $text = trim((string) $value);
        return match ($type) {
            'int', 'integer', 'positive-int', 'non-negative-int' =>
                filter_var($text, FILTER_VALIDATE_INT) !== false
                    ? (int) $text
                    : throw new InvalidArgumentException('form_integer_invalid'),
            'float', 'number' => filter_var($text, FILTER_VALIDATE_FLOAT) !== false ? (float) $text : throw new InvalidArgumentException('form_number_invalid'),
            'bool', 'boolean' => match (strtolower($text)) {
                '1', 'true', 'on', 'yes' => true,
                '0', 'false', 'off', 'no' => false,
                default => throw new InvalidArgumentException('form_boolean_invalid'),
            },
            'string', 'non-empty-string', 'identifier' => $text,
            default => throw new InvalidArgumentException('form_complex_type_unsupported'),
        };
    }
}
