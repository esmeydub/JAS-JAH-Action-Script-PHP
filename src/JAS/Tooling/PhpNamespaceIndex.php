<?php

declare(strict_types=1);

namespace Jah\JAS\Tooling;

final class PhpNamespaceIndex
{
    /** @return array{symbols:list<array{name:string,file:string,line:int}>,imports:list<array{name:string,file:string,line:int}>} */
    public function scan(string $source, string $file): array
    {
        $tokens = token_get_all($source);
        $namespace = '';
        $symbols = [];
        $imports = [];
        $seenDeclaration = false;
        $previous = null;
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token)) continue;
            [$kind, , $line] = $token;
            if ($kind === T_NAMESPACE) {
                [$namespace, $index] = $this->qualifiedName($tokens, $index + 1, [';', '{']);
                $namespace = trim($namespace, '\\');
                $previous = T_NAMESPACE;
                continue;
            }
            if ($kind === T_USE && !$seenDeclaration) {
                [$name, $index, $supported] = $this->importName($tokens, $index + 1);
                if ($supported && str_starts_with($name, 'App\\')) $imports[] = ['name' => $name, 'file' => $file, 'line' => $line];
                $previous = T_USE;
                continue;
            }
            if (in_array($kind, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                if ($kind === T_CLASS && $previous === T_NEW) { $previous = $kind; continue; }
                $seenDeclaration = true;
                for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                    $candidate = $tokens[$cursor];
                    if (is_array($candidate) && $candidate[0] === T_STRING) {
                        $name = ($namespace === '' ? '' : $namespace . '\\') . $candidate[1];
                        $symbols[] = ['name' => $name, 'file' => $file, 'line' => $candidate[2]];
                        $index = $cursor;
                        break;
                    }
                    if (!is_array($candidate) && $candidate === '{') break;
                }
                $previous = $kind;
                continue;
            }
            if (!in_array($kind, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $previous = $kind;
        }
        return ['symbols' => $symbols, 'imports' => $imports];
    }

    /** @return array{string,int} */
    private function qualifiedName(array $tokens, int $index, array $terminators): array
    {
        $name = '';
        for ($count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) && in_array($token, $terminators, true)) return [$name, $index];
            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR], true)) $name .= $token[1];
        }
        return [$name, $index];
    }

    /** @return array{string,int,bool} */
    private function importName(array $tokens, int $index): array
    {
        $name = '';
        $supported = true;
        for ($count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) && $token === ';') return [trim($name, '\\'), $index, $supported];
            if (!is_array($token) && in_array($token, [',', '{', '}'], true)) $supported = false;
            if (is_array($token) && in_array($token[0], [T_FUNCTION, T_CONST], true)) $supported = false;
            if (is_array($token) && $token[0] === T_AS) {
                while ($index < $count && $tokens[$index] !== ';') $index++;
                return [trim($name, '\\'), $index, $supported];
            }
            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR], true)) $name .= $token[1];
        }
        return [$name, $index, false];
    }
}
