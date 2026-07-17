<?php

declare(strict_types=1);

namespace Jah\JAS\Type;

use InvalidArgumentException;

final class TypeRegistry
{
    /** @var array<string,array{fields:array<string,string>,strict:bool}> */
    private array $types = [];
    /** @var array<string,string> */
    private array $aliases = [];

    public function alias(string $name, string $expression): self
    {
        if (!preg_match('/^[A-Z][A-Za-z0-9_]{1,127}$/', $name)) throw new InvalidArgumentException('type_alias_invalid');
        if ($expression === '' || $expression === $name || isset($this->types[$name]) || isset($this->aliases[$name])) throw new InvalidArgumentException('type_alias_invalid');
        $this->aliases[$name] = $expression;
        return $this;
    }

    public function aliasExpression(string $name): ?string { return $this->aliases[$name] ?? null; }

    public function define(string $name, array $fields, bool $strict = true): self
    {
        if (!preg_match('/^[A-Z][A-Za-z0-9_]{1,127}$/', $name)) throw new InvalidArgumentException('type_name_invalid');
        if (isset($this->types[$name])) throw new InvalidArgumentException('type_already_defined');
        foreach ($fields as $field => $expression) {
            if (!is_string($field) || !preg_match('/^[a-z_][a-z0-9_]*\??$/i', $field) || !is_string($expression) || $expression === '') {
                throw new InvalidArgumentException('type_field_invalid');
            }
        }
        $this->types[$name] = ['fields' => $fields, 'strict' => $strict];
        return $this;
    }

    public function assert(string $type, mixed $value, string $label = 'value'): mixed
    {
        if (!$this->matches($type, $value, [])) throw new InvalidArgumentException("{$label}_type_mismatch:{$type}");
        return $value;
    }

    public function validate(string $type, mixed $value): bool { return $this->matches($type, $value, []); }

    public function has(string $type): bool { return isset($this->types[$type]); }
    public function definition(string $type): array
    {
        return $this->types[$type] ?? throw new InvalidArgumentException('type_not_defined');
    }

    public function describe(): array
    {
        $types = $this->types; $aliases = $this->aliases;
        ksort($types); ksort($aliases);
        return ['definitions' => $types, 'aliases' => $aliases];
    }

    public function fingerprint(): string
    {
        return hash('sha256', serialize($this->describe()));
    }

    private function matches(string $expression, mixed $value, array $stack): bool
    {
        foreach (explode('|', $expression) as $candidate) {
            if ($this->matchesOne(trim($candidate), $value, $stack)) return true;
        }
        return false;
    }

    private function matchesOne(string $type, mixed $value, array $stack): bool
    {
        if (isset($this->aliases[$type])) {
            if (in_array($type, $stack, true)) return false;
            return $this->matches($this->aliases[$type], $value, [...$stack, $type]);
        }
        if (str_ends_with($type, '[]')) {
            if (!is_array($value) || !array_is_list($value)) return false;
            $itemType = substr($type, 0, -2);
            foreach ($value as $item) if (!$this->matches($itemType, $item, $stack)) return false;
            return true;
        }
        if (isset($this->types[$type])) {
            if (!is_array($value) || in_array($type, $stack, true)) return false;
            $definition = $this->types[$type];
            foreach ($definition['fields'] as $field => $fieldType) {
                $optional = str_ends_with($field, '?');
                $name = $optional ? substr($field, 0, -1) : $field;
                if (!array_key_exists($name, $value)) { if ($optional) continue; return false; }
                if (!$this->matches($fieldType, $value[$name], [...$stack, $type])) return false;
            }
            if ($definition['strict']) {
                $allowed = array_map(static fn(string $field): string => rtrim($field, '?'), array_keys($definition['fields']));
                if (array_diff(array_keys($value), $allowed) !== []) return false;
            }
            return true;
        }
        return match ($type) {
            'mixed', 'any' => true, 'null' => $value === null,
            'string' => is_string($value), 'non-empty-string' => is_string($value) && trim($value) !== '',
            'identifier' => is_string($value) && preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $value) === 1,
            'date' => self::validDate($value), 'datetime' => self::validDateTime($value),
            'timezone' => self::validTimezone($value),
            'int', 'integer' => is_int($value), 'positive-int' => is_int($value) && $value > 0,
            'non-negative-int' => is_int($value) && $value >= 0,
            'float' => is_float($value), 'number' => is_int($value) || is_float($value),
            'bool', 'boolean' => is_bool($value), 'array' => is_array($value),
            'map' => is_array($value) && !array_is_list($value), 'list' => is_array($value) && array_is_list($value),
            'object' => is_object($value), 'callable' => is_callable($value),
            default => class_exists($type) && $value instanceof $type,
        };
    }

    private static function validDate(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) return false;
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private static function validDateTime(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) return false;
        $format = str_ends_with($value, 'Z') ? '!Y-m-d\TH:i:s\Z' : '!Y-m-d\TH:i:sP';
        $date = \DateTimeImmutable::createFromFormat($format, $value);
        $errors = \DateTimeImmutable::getLastErrors();
        return $date instanceof \DateTimeImmutable
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format(substr($format, 1)) === $value;
    }

    private static function validTimezone(mixed $value): bool
    {
        if (!is_string($value) || $value === '' || strlen($value) > 128) return false;
        try { new \DateTimeZone($value); return true; } catch (\Throwable) { return false; }
    }
}
