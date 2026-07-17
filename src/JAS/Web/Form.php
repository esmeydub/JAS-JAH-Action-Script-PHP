<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use DateTimeImmutable;
use DateTimeZone;
use Jah\JAS\Type\TypeRegistry;
use InvalidArgumentException;

final class Form implements Component
{
    private array $values = [];
    private array $errors = [];
    private readonly Translator $translator;

    public function __construct(
        private readonly TypeRegistry $types,
        private readonly string $type,
        private readonly string $action,
        private readonly string $csrfToken,
        private readonly array $labels = [],
        private readonly string $method = 'POST',
        private readonly array $controls = [],
        ?Translator $translator = null,
    ) {
        if (!$types->has($type)) throw new InvalidArgumentException('form_type_not_defined');
        if (!in_array($method, ['POST', 'GET'], true)) throw new InvalidArgumentException('form_method_invalid');
        if (!str_starts_with($action, '/') || str_contains($action, '..')) throw new InvalidArgumentException('form_action_invalid');
        if (strlen($csrfToken) < 32) throw new InvalidArgumentException('form_csrf_invalid');
        $this->translator = $translator ?? WebTranslations::translator();
        $fields = array_map(static fn(string $field): string => rtrim($field, '?'), array_keys($types->definition($type)['fields']));
        foreach ($controls as $name => $control) {
            if (!is_string($name) || !in_array($name, $fields, true) || !$control instanceof FormControl) {
                throw new InvalidArgumentException('form_control_invalid');
            }
            $expression = $this->expressionFor($name);
            if (($control->kind === 'date' && $expression !== 'date')
                || ($control->kind === 'datetime-local' && $expression !== 'datetime')
                || ($control->kind === 'timezone' && $expression !== 'timezone')
                || ($control->kind === 'file' && $expression !== UploadedFile::class)) {
                throw new InvalidArgumentException('form_control_contract_mismatch');
            }
        }
    }

    /** @return array{valid:bool,data:array,errors:array<string,string>} */
    public function submit(array $input, array $files = []): array
    {
        $this->values = $input; $this->errors = []; $data = [];
        $submittedCsrf = $input['_csrf'] ?? null;
        if (!is_string($submittedCsrf) || !hash_equals($this->csrfToken, $submittedCsrf)) $this->errors['_csrf'] = 'invalid_csrf';
        foreach ($this->types->definition($this->type)['fields'] as $field => $expression) {
            $optional = str_ends_with($field, '?'); $name = $optional ? substr($field, 0, -1) : $field;
            $control = $this->controls[$name] ?? null;
            $source = $control?->kind === 'file' ? $files : $input;
            if (!array_key_exists($name, $source) || $source[$name] === '') {
                if (!$optional) $this->errors[$name] = 'required';
                continue;
            }
            try {
                $value = $source[$name];
                if ($control?->kind === 'file' && is_array($value)) $value = UploadedFile::fromPhpUpload($value);
                $data[$name] = $control === null
                    ? $this->coerce((string) $expression, $value)
                    : $this->coerceControl($control, (string) $expression, $value, $input);
            }
            catch (InvalidArgumentException) { $this->errors[$name] = 'invalid_type'; }
            catch (\RuntimeException) { $this->errors[$name] = 'invalid_value'; }
        }
        $allowed = array_map(static fn(string $field): string => rtrim($field, '?'), array_keys($this->types->definition($this->type)['fields']));
        foreach (array_diff(array_keys($input), [...$allowed, '_csrf']) as $unknown) $this->errors[(string) $unknown] = 'unknown_field';
        foreach (array_diff(array_keys($files), $allowed) as $unknown) $this->errors[(string) $unknown] = 'unknown_file';
        if ($this->errors === [] && !$this->types->validate($this->type, $data)) $this->errors['_form'] = 'contract_mismatch';
        return ['valid' => $this->errors === [], 'data' => $data, 'errors' => $this->errors];
    }

    public function render(): SafeHtml
    {
        $children = [Html::element('input', ['type' => 'hidden', 'name' => '_csrf', 'value' => $this->csrfToken])];
        foreach (['_csrf', '_form'] as $formError) {
            if (isset($this->errors[$formError])) $children[] = Html::element('p', ['role' => 'alert'], $this->errorMessage($this->errors[$formError]));
        }
        foreach ($this->types->definition($this->type)['fields'] as $field => $expression) {
            $optional = str_ends_with($field, '?'); $name = $optional ? substr($field, 0, -1) : $field;
            $id = 'jas-field-' . $name;
            $errorId = $id . '-error';
            $control = $this->controls[$name] ?? null;
            $inputType = $control === null
                ? (in_array($expression, ['int', 'integer', 'positive-int', 'non-negative-int', 'number', 'float'], true) ? 'number' : 'text')
                : $control->kind;
            $attributes = [
                'id' => $id,
                'name' => $control?->multiple ? $name . '[]' : $name,
                'required' => !$optional,
                'aria-invalid' => isset($this->errors[$name]) ? 'true' : 'false',
                'aria-describedby' => isset($this->errors[$name]) ? $errorId : null,
            ];
            if ($control?->kind === 'select' || $control?->kind === 'timezone') {
                $attributes['multiple'] = $control->multiple;
                $selected = $this->values[$name] ?? ($control->multiple ? [] : '');
                $options = [];
                foreach ($control->options as $value => $label) {
                    $options[] = Html::element('option', [
                        'value' => $value,
                        'selected' => $control->multiple ? in_array($value, is_array($selected) ? $selected : [], true) : (string) $selected === $value,
                    ], $label);
                }
                $fieldControl = Html::element('select', $attributes, $options);
            } else {
                $attributes['type'] = $inputType;
                if ($control?->kind !== 'file') $attributes['value'] = (string) ($this->values[$name] ?? '');
                if ($control?->kind === 'date') { $attributes['min'] = $control->minimum; $attributes['max'] = $control->maximum; }
                if ($control?->kind === 'file') {
                    if ($control->uploadPolicy === null) throw new InvalidArgumentException('form_control_upload_policy_missing');
                    $attributes['accept'] = implode(',', $control->uploadPolicy->allowedMimeTypes);
                }
                $fieldControl = Html::element('input', $attributes);
            }
            $children[] = Html::element('div', ['class' => 'jas-field'],
                Html::element('label', ['for' => $id], $this->labels[$name] ?? ucfirst(str_replace('_', ' ', $name))),
                $fieldControl,
                isset($this->errors[$name]) ? Html::element('span', ['id' => $errorId, 'role' => 'alert'], $this->errorMessage($this->errors[$name])) : null
            );
        }
        $children[] = Html::element('button', ['type' => 'submit'], $this->translator->text('form.submit'));
        $hasFiles = false;
        foreach ($this->controls as $control) if ($control->kind === 'file') $hasFiles = true;
        return Html::element('form', [
            'method' => $this->method,
            'action' => $this->action,
            'enctype' => $hasFiles ? 'multipart/form-data' : null,
        ], $children);
    }

    public function uploadPolicy(string $field): UploadPolicy
    {
        $control = $this->controls[$field] ?? null;
        return $control instanceof FormControl && $control->uploadPolicy instanceof UploadPolicy
            ? $control->uploadPolicy
            : throw new InvalidArgumentException('form_upload_policy_not_found');
    }

    private function coerceControl(FormControl $control, string $expression, mixed $value, array $input): mixed
    {
        if ($control->kind === 'file') {
            if (!$value instanceof UploadedFile) throw new InvalidArgumentException('form_file_invalid');
            return $value;
        }
        if ($control->kind === 'select' || $control->kind === 'timezone') {
            if ($control->multiple) {
                if (!is_array($value) || !array_is_list($value) || $value === []) throw new InvalidArgumentException('form_select_invalid');
                $selected = [];
                foreach ($value as $item) {
                    if (!is_string($item) || !array_key_exists($item, $control->options)) throw new InvalidArgumentException('form_select_invalid');
                    $selected[] = $item;
                }
                return array_values(array_unique($selected));
            }
            if (!is_string($value) || !array_key_exists($value, $control->options)) throw new InvalidArgumentException('form_select_invalid');
            return $this->coerce($expression, $value);
        }
        if ($control->kind === 'date') {
            $date = $this->coerce('date', $value);
            if (($control->minimum !== null && $date < $control->minimum) || ($control->maximum !== null && $date > $control->maximum)) {
                throw new InvalidArgumentException('form_date_range_invalid');
            }
            return $date;
        }
        if ($control->kind === 'datetime-local') {
            if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) !== 1) throw new InvalidArgumentException('form_datetime_invalid');
            $timezone = $input[$control->timezoneField ?? ''] ?? null;
            if (!is_string($timezone)) throw new InvalidArgumentException('form_timezone_invalid');
            try { $zone = new DateTimeZone($timezone); } catch (\Throwable) { throw new InvalidArgumentException('form_timezone_invalid'); }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $zone);
            if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d\TH:i') !== $value) throw new InvalidArgumentException('form_datetime_invalid');
            return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        return $this->coerce($expression, $value);
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
            'date' => $this->validDate($text) ? $text : throw new InvalidArgumentException('form_date_invalid'),
            'datetime' => (new TypeRegistry())->validate('datetime', $text) ? $text : throw new InvalidArgumentException('form_datetime_invalid'),
            'timezone' => (new TypeRegistry())->validate('timezone', $text) ? $text : throw new InvalidArgumentException('form_timezone_invalid'),
            'string', 'non-empty-string', 'identifier' => $text,
            default => throw new InvalidArgumentException('form_complex_type_unsupported'),
        };
    }

    private function expressionFor(string $name): string
    {
        foreach ($this->types->definition($this->type)['fields'] as $field => $expression) {
            if (rtrim($field, '?') === $name) return (string) $expression;
        }
        throw new InvalidArgumentException('form_field_not_found');
    }

    private function validDate(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1) return false;
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private function errorMessage(string $code): string
    {
        return $this->translator->text('form.error.' . $code);
    }
}
