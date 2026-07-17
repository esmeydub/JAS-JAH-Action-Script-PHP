<?php

declare(strict_types=1);

namespace Jah\Core;

use Jah\Agents\BaseAgent;

/**
 * EventRouter — Enrutador de eventos.
 * Direcciona eventos entrantes del Gateway o internos a los agentes correspondientes.
 */
class EventRouter
{
    /** @var array<string, array<BaseAgent>> Mapeo de patrones de eventos a agentes */
    private array $routes = [];

    /**
     * Registra un agente para manejar un tipo de evento o patrón (con comodín '*').
     *
     * @param string $pattern Patrón de evento (ej: 'http.request', 'metrics.*', '*')
     * @param BaseAgent $agent Agente que procesará el evento
     */
    public function route(string $pattern, BaseAgent $agent): void
    {
        $this->routes[$pattern][] = $agent;
    }

    /**
     * Enruta un evento a todos los agentes que coincidan con su tipo.
     *
     * @param array $event Estructura del evento (id, type, payload, source, timestamp)
     * @return int Cantidad de agentes que procesaron el evento
     */
    public function dispatch(array $event): int
    {
        $type = $event['type'] ?? '';
        $dispatchedCount = 0;

        foreach ($this->routes as $pattern => $agents) {
            if ($this->match($pattern, $type)) {
                foreach ($agents as $agent) {
                    try {
                        $agent->handle($event);
                        $dispatchedCount++;
                    } catch (\Throwable $e) {
                        // Capturar errores en la ejecución del agente
                        error_log("Error al despachar evento '{$type}' al agente " . get_class($agent) . ": " . $e->getMessage());
                    }
                }
            }
        }

        return $dispatchedCount;
    }

    /**
     * Verifica si un tipo de evento coincide con un patrón.
     * Soporta coincidencia exacta y comodín al final (ej: 'db.*').
     */
    private function match(string $pattern, string $type): bool
    {
        if ($pattern === '*' || $pattern === $type) {
            return true;
        }

        if (str_contains($pattern, '*')) {
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
            return (bool) preg_match($regex, $type);
        }

        return false;
    }
}
