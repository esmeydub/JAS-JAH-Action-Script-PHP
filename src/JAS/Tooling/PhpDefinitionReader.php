<?php

declare(strict_types=1);

namespace Jah\JAS\Tooling;

use RuntimeException;

final class PhpDefinitionReader
{
    /** @var list<array{kind:int|string,text:string}> */
    private array $tokens = [];
    private int $position = 0;
    private int $items = 0;

    public function read(string $file): array
    {
        $source = @file_get_contents($file);
        if (!is_string($source) || strlen($source) > 1_048_576) throw new RuntimeException('generated_definition_read_failed');
        return $this->readSource($source);
    }

    public function readSource(string $source): array
    {
        if (strlen($source) > 1_048_576 || preg_match('//u', $source) !== 1) throw new RuntimeException('generated_definition_read_failed');
        $prefix = '/\A<\?php\s+declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*return\s+/A';
        if (preg_match($prefix, $source, $match) !== 1) throw new RuntimeException('generated_definition_prefix_invalid');
        $expression = substr($source, strlen($match[0]));
        $raw = token_get_all("<?php " . $expression);
        $this->tokens = [];
        foreach ($raw as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
                $this->tokens[] = ['kind' => $token[0], 'text' => $token[1]];
            } else {
                $this->tokens[] = ['kind' => $token, 'text' => $token];
            }
        }
        $this->position = 0;
        $this->items = 0;
        $value = $this->value(0);
        $this->take(';');
        if ($this->position !== count($this->tokens) || !is_array($value) || array_is_list($value)) {
            throw new RuntimeException('generated_definition_invalid');
        }
        return $value;
    }

    private function value(int $depth): mixed
    {
        if ($depth > 16) throw new RuntimeException('generated_definition_too_deep');
        $token = $this->peek();
        if ($token['kind'] === '[') return $this->array($depth + 1);
        $this->position++;
        if ($token['kind'] === T_CONSTANT_ENCAPSED_STRING) return $this->string($token['text']);
        if ($token['kind'] === T_LNUMBER && preg_match('/^(?:0|[1-9][0-9]*)$/', $token['text']) === 1) return (int) $token['text'];
        if ($token['kind'] === T_STRING) {
            return match (strtolower($token['text'])) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => throw new RuntimeException('generated_definition_value_invalid'),
            };
        }
        throw new RuntimeException('generated_definition_value_invalid');
    }

    private function array(int $depth): array
    {
        $this->take('[');
        $result = [];
        while ($this->peek()['kind'] !== ']') {
            if (++$this->items > 10_000) throw new RuntimeException('generated_definition_too_large');
            $first = $this->value($depth);
            if ($this->peek()['kind'] === T_DOUBLE_ARROW) {
                $this->position++;
                if (!is_int($first) && !is_string($first)) throw new RuntimeException('generated_definition_key_invalid');
                if (array_key_exists($first, $result)) throw new RuntimeException('generated_definition_key_duplicated');
                $result[$first] = $this->value($depth);
            } else {
                $result[] = $first;
            }
            if ($this->peek()['kind'] !== ',') break;
            $this->position++;
            $next = $this->tokens[$this->position] ?? throw new RuntimeException('generated_definition_truncated');
            if ($next['kind'] === ']') break;
        }
        $this->take(']');
        return $result;
    }

    /** @return array{kind:int|string,text:string} */
    private function peek(): array
    {
        return $this->tokens[$this->position] ?? throw new RuntimeException('generated_definition_truncated');
    }

    private function take(int|string $kind): void
    {
        if ($this->peek()['kind'] !== $kind) throw new RuntimeException('generated_definition_syntax_invalid');
        $this->position++;
    }

    private function string(string $literal): string
    {
        if (!str_starts_with($literal, "'") || !str_ends_with($literal, "'")) {
            throw new RuntimeException('generated_definition_string_invalid');
        }
        $inner = substr($literal, 1, -1);
        if (preg_match('/\\\\(?!\\\\|\')/', $inner) === 1) throw new RuntimeException('generated_definition_string_escape_invalid');
        return str_replace(["\\'", "\\\\"], ["'", "\\"], $inner);
    }
}
