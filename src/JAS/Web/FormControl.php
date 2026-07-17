<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use DateTimeZone;
use RuntimeException;

final class FormControl
{
    /**
     * @param array<string,string> $options
     */
    private function __construct(
        public readonly string $kind,
        public readonly array $options = [],
        public readonly bool $multiple = false,
        public readonly ?string $minimum = null,
        public readonly ?string $maximum = null,
        public readonly ?string $timezoneField = null,
        public readonly ?UploadPolicy $uploadPolicy = null,
    ) {}

    public static function date(?string $minimum = null, ?string $maximum = null): self
    {
        foreach ([$minimum, $maximum] as $date) {
            if ($date !== null && !self::validDate($date)) throw new RuntimeException('form_control_date_limit_invalid');
        }
        if ($minimum !== null && $maximum !== null && $minimum > $maximum) throw new RuntimeException('form_control_date_range_invalid');
        return new self('date', minimum: $minimum, maximum: $maximum);
    }

    public static function dateTime(string $timezoneField): self
    {
        if (!preg_match('/^[a-z_][a-z0-9_]{0,63}$/', $timezoneField)) throw new RuntimeException('form_control_timezone_field_invalid');
        return new self('datetime-local', timezoneField: $timezoneField);
    }

    /** @param array<string,string> $options */
    public static function timezone(array $options): self
    {
        self::validateOptions($options);
        foreach (array_keys($options) as $timezone) {
            try { new DateTimeZone($timezone); } catch (\Throwable) { throw new RuntimeException('form_control_timezone_invalid'); }
        }
        return new self('timezone', options: $options);
    }

    /** @param array<string,string> $options */
    public static function select(array $options, bool $multiple = false): self
    {
        self::validateOptions($options);
        return new self('select', options: $options, multiple: $multiple);
    }

    public static function file(UploadPolicy $policy): self
    {
        return new self('file', uploadPolicy: $policy);
    }

    /** @param array<string,string> $options */
    private static function validateOptions(array $options): void
    {
        if ($options === [] || array_is_list($options) || count($options) > 500) throw new RuntimeException('form_control_options_invalid');
        foreach ($options as $value => $label) {
            if (!is_string($value) || $value === '' || strlen($value) > 128 || preg_match('/[\x00-\x1F\x7F]/', $value)
                || !is_string($label) || trim($label) === '' || strlen($label) > 200) {
                throw new RuntimeException('form_control_options_invalid');
            }
        }
    }

    private static function validDate(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1) return false;
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
