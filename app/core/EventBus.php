<?php

declare(strict_types=1);

namespace Jah\Core;

/**
 * EventBus — Bus de comunicación interno de publicación/suscripción.
 * Permite que los agentes se comuniquen de forma desacoplada.
 */
class EventBus
{
    /** @var array<string, array<callable>> Lista de suscriptores por tipo de evento */
    private array $listeners = [];

    /**
     * Suscribirse a un tipo de evento.
     *
     * @param string $eventType Tipo de evento (ej. 'system.boot', 'agent.spawned')
     * @param callable $callback Función a ejecutar al recibir el evento
     */
    public function subscribe(string $eventType, callable $callback): void
    {
        $this->listeners[$eventType][] = $callback;
    }

    /**
     * Publicar un evento en el bus.
     *
     * @param string $eventType Tipo de evento
     * @param array $payload Datos del evento
     * @param string $source Origen del evento (ej. nombre del agente)
     */
    public function publish(string $eventType, array $payload = [], string $source = 'system'): void
    {
        $event = [
            'id' => uniqid('evt_', true),
            'type' => $eventType,
            'payload' => $payload,
            'source' => $source,
            'timestamp' => microtime(true),
        ];

        // Notificar a oyentes específicos del tipo de evento
        if (isset($this->listeners[$eventType])) {
            foreach ($this->listeners[$eventType] as $callback) {
                try {
                    $callback($event);
                } catch (\Throwable $e) {
                    // Aquí el motor o un log local debería capturar fallos de callbacks
                    error_log("Error en listener de evento '{$eventType}': " . $e->getMessage());
                }
            }
        }

        // Notificar a los oyentes globales (suscritos a '*')
        if (isset($this->listeners['*'])) {
            foreach ($this->listeners['*'] as $callback) {
                try {
                    $callback($event);
                } catch (\Throwable $e) {
                    error_log("Error en listener global para '{$eventType}': " . $e->getMessage());
                }
            }
        }
    }
}
