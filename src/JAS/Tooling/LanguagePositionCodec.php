<?php

declare(strict_types=1);

namespace Jah\JAS\Tooling;

use RuntimeException;

final class LanguagePositionCodec
{
    public function offset(string $source, int $line, int $character, string $encoding): int
    {
        $this->validate($source, $line, $character, $encoding);
        [$start, $text] = $this->line($source, $line);
        $units = 0;
        $bytes = 0;
        foreach ($this->characters($text) as $value) {
            if ($units === $character) return $start + $bytes;
            $width = $this->units($value, $encoding);
            if ($units + $width > $character) throw new RuntimeException('language_position_splits_character');
            $units += $width;
            $bytes += strlen($value);
        }
        if ($units !== $character) throw new RuntimeException('language_position_out_of_range');
        return $start + $bytes;
    }

    /** @return array{line:int,character:int} */
    public function position(string $source, int $offset, string $encoding): array
    {
        $this->validate($source, 0, 0, $encoding);
        if ($offset < 0 || $offset > strlen($source) || ($offset < strlen($source) && (ord($source[$offset]) & 0xC0) === 0x80)) {
            throw new RuntimeException('language_offset_invalid');
        }
        $before = substr($source, 0, $offset);
        $line = substr_count($before, "\n");
        $newline = strrpos($before, "\n");
        $lineBytes = $newline === false ? $before : substr($before, $newline + 1);
        if (str_contains($lineBytes, "\r")) throw new RuntimeException('language_offset_inside_line_ending');
        $units = 0;
        foreach ($this->characters($lineBytes) as $value) $units += $this->units($value, $encoding);
        return ['line' => $line, 'character' => $units];
    }

    /** @return array{int,string} */
    private function line(string $source, int $target): array
    {
        $start = 0;
        for ($line = 0; $line < $target; $line++) {
            $newline = strpos($source, "\n", $start);
            if ($newline === false) throw new RuntimeException('language_position_out_of_range');
            $start = $newline + 1;
        }
        $end = strpos($source, "\n", $start);
        if ($end === false) $end = strlen($source);
        if ($end > $start && $source[$end - 1] === "\r") $end--;
        return [$start, substr($source, $start, $end - $start)];
    }

    /** @return list<string> */
    private function characters(string $value): array
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($characters)) throw new RuntimeException('language_source_utf8_invalid');
        return $characters;
    }

    private function units(string $value, string $encoding): int
    {
        return match ($encoding) {
            'utf-8' => strlen($value),
            'utf-16' => strlen($value) === 4 ? 2 : 1,
            'utf-32' => 1,
            default => throw new RuntimeException('language_position_encoding_invalid'),
        };
    }

    private function validate(string $source, int $line, int $character, string $encoding): void
    {
        if ($line < 0 || $character < 0 || strlen($source) > 16_777_216 || preg_match('//u', $source) !== 1) {
            throw new RuntimeException('language_position_invalid');
        }
        if (!in_array($encoding, ['utf-8', 'utf-16', 'utf-32'], true)) throw new RuntimeException('language_position_encoding_invalid');
    }
}
