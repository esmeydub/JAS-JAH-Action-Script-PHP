<?php

declare(strict_types=1);

namespace Jah;

use RuntimeException;

final class JahEngineJas
{
    private array $policies = [];
    private array $evaluations = [];

    public function load(string $file): void
    {
        $content = file_get_contents($file);
        if ($content === false) {
            throw new RuntimeException("Cannot read JAS policy: {$file}");
        }
        $this->loadString($content);
    }

    public function loadString(string $content): void
    {
        $this->parse($content);
    }

    private function parse(string $content): void
    {
        if (!preg_match_all('/policy\("([^"]+)"\)(.*?)(?=\bpolicy\("|\z)/s', $content, $matches, PREG_SET_ORDER)) {
            throw new RuntimeException('No valid policy declarations found');
        }

        foreach ($matches as $match) {
            $body = $match[2];
            $policy = [
                'observe_ms' => $this->singleInt($body, 'observe', 's', 0) * 1000,
                'stability_windows' => $this->singleInt($body, 'stability_windows', '', 1),
                'cooldown_ms' => $this->singleInt($body, 'cooldown', 's', 0) * 1000,
                'rollback_loss_pct' => $this->singleInt($body, 'rollback_loss', '%', 0),
                'custom_cap' => $this->singleInt($body, 'custom_cap', '', 100),
                'workers' => [1, 1],
                'requirements' => [],
            ];
            if (preg_match('/workers\(\s*(\d+)\s*,\s*(\d+)\s*\)/', $body, $workers)) {
                $policy['workers'] = [(int) $workers[1], (int) $workers[2]];
            }
            if (preg_match_all('/require\("([^"]+)",\s*"(==|===|!=|!==|>=|<=|>|<)",\s*(true|false|null|-?\d+(?:\.\d+)?|"[^"]*")\s*\)/', $body, $requirements, PREG_SET_ORDER)) {
                foreach ($requirements as $requirement) {
                    $policy['requirements'][] = [
                        'field' => $requirement[1],
                        'operator' => $requirement[2],
                        'value' => $this->parseLiteral($requirement[3]),
                    ];
                }
            }
            $this->policies[$match[1]] = $policy;
        }
    }

    public function evaluate(string $policyName, array $context): bool
    {
        if (!isset($this->policies[$policyName])) {
            return false;
        }

        $passed = true;
        foreach ($this->policies[$policyName]['requirements'] as $requirement) {
            $actual = $context[$requirement['field']] ?? null;
            if (!$this->compare($actual, $requirement['operator'], $requirement['value'])) {
                $passed = false;
                break;
            }
        }
        $this->evaluations[] = ['policy' => $policyName, 'passed' => $passed, 'ts' => microtime(true)];
        return $passed;
    }

    public function getPolicy(string $policyName): ?array
    {
        return $this->policies[$policyName] ?? null;
    }

    public function getStats(): array
    {
        return [
            'policies_loaded' => count($this->policies),
            'evaluations' => count($this->evaluations),
            'passed' => count(array_filter($this->evaluations, static fn(array $item): bool => $item['passed'])),
        ];
    }

    private function singleInt(string $body, string $name, string $suffix, int $default): int
    {
        $suffixPattern = $suffix === '' ? '' : preg_quote($suffix, '/');
        return preg_match('/' . preg_quote($name, '/') . '\(\s*(\d+)\s*' . $suffixPattern . '\s*\)/', $body, $match)
            ? (int) $match[1]
            : $default;
    }

    private function parseLiteral(string $literal): mixed
    {
        return match ($literal) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => str_starts_with($literal, '"')
                ? substr($literal, 1, -1)
                : (str_contains($literal, '.') ? (float) $literal : (int) $literal),
        };
    }

    private function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            '==' => $actual == $expected,
            '===' => $actual === $expected,
            '!=' => $actual != $expected,
            '!==' => $actual !== $expected,
            '>' => $actual > $expected,
            '>=' => $actual >= $expected,
            '<' => $actual < $expected,
            '<=' => $actual <= $expected,
        };
    }
}
