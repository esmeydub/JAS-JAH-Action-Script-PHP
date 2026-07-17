<?php

declare(strict_types=1);

namespace Jah\JAS\Action;

use Fiber;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ActionScript
{
    private static array $registry = [];

    private string $name;
    private array $requiredParams = [];
    private int $timeoutMs = 5000;
    private mixed $handlerCallback = null;

    public function __construct(string $name)
    {
        if (!preg_match('/^[a-z][a-z0-9_.:-]*$/', $name)) {
            throw new InvalidArgumentException("Nombre de acción no válido: {$name}");
        }
        $this->name = $name;
        self::$registry[$name] = $this;
    }

    public static function define(string $name): self
    {
        return new self($name);
    }

    public function requires(array $params): self
    {
        foreach ($params as $param) {
            if (!is_string($param) || !preg_match('/^[a-z_][a-z0-9_]*$/i', $param)) {
                throw new InvalidArgumentException('Cada parámetro requerido debe ser un identificador válido.');
            }
        }
        $this->requiredParams = array_values(array_unique($params));
        return $this;
    }

    public function timeout(int $ms): self
    {
        if ($ms < 1) {
            throw new InvalidArgumentException('El presupuesto de tiempo debe ser mayor que cero.');
        }
        $this->timeoutMs = $ms;
        return $this;
    }

    public function handler(callable $callback): self
    {
        $this->handlerCallback = $callback;
        return $this;
    }

    public function getName(): string { return $this->name; }

    public function execute(array $data): array
    {
        foreach ($this->requiredParams as $param) {
            if (!array_key_exists($param, $data)) {
                return $this->failure("Falta el parámetro requerido: {$param}");
            }
        }

        if ($this->handlerCallback === null) {
            return $this->failure('Handler no definido');
        }

        $startedAt = hrtime(true);
        try {
            $fiber = new Fiber(fn() => ($this->handlerCallback)($data));
            $fiber->start();

            while (!$fiber->isTerminated()) {
                $fiber->resume();
                $elapsed = (hrtime(true) - $startedAt) / 1_000_000;
                if ($elapsed > $this->timeoutMs) {
                    return $this->failure(
                        "La acción excedió su presupuesto de {$this->timeoutMs} ms",
                        $elapsed,
                        'time_budget_exceeded'
                    );
                }
            }

            $result = $fiber->getReturn();
            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
            if ($durationMs > $this->timeoutMs) {
                return [
                    'success' => true,
                    'result' => $result,
                    'action' => $this->name,
                    'duration_ms' => $durationMs,
                    'budget_exceeded' => true,
                    'warning' => "La acción terminó después de su presupuesto de {$this->timeoutMs} ms",
                ];
            }
            return [
                'success' => true,
                'result' => $result,
                'action' => $this->name,
                'duration_ms' => $durationMs,
            ];
        } catch (Throwable $error) {
            return $this->failure($error->getMessage(), (hrtime(true) - $startedAt) / 1_000_000);
        }
    }

    private function failure(string $message, ?float $durationMs = null, string $code = 'action_failed'): array
    {
        $result = [
            'success' => false,
            'error' => $message,
            'error_code' => $code,
            'action' => $this->name,
        ];
        if ($durationMs !== null) {
            $result['duration_ms'] = $durationMs;
        }
        return $result;
    }

    public static function run(string $name, array $data): array
    {
        if (!isset(self::$registry[$name])) {
            return ['success' => false, 'error' => "Acción no encontrada: {$name}", 'action' => $name];
        }
        return self::$registry[$name]->execute($data);
    }
}
