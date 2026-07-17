<?php

declare(strict_types=1);

namespace Jah\Core;

use Jah\Agents\BaseAgent;

/**
 * JahEngine — El núcleo y orquestador central del Motor PHP JAH.
 * Coordina la inicialización de agentes, el bus de eventos y el enrutamiento.
 */
class JahEngine
{
    private static ?JahEngine $instance = null;

    private array $config = [];
    private EventBus $eventBus;
    private EventRouter $eventRouter;

    /** @var array<string, BaseAgent> Instancias de agentes activos */
    private array $agents = [];
    private bool $booted = false;

    private function __construct()
    {
        $this->eventBus = new EventBus();
        $this->eventRouter = new EventRouter();
    }

    public static function getInstance(): JahEngine
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Inicializa el motor con su configuración global.
     */
    public function boot(array $config): void
    {
        if ($this->booted) {
            return;
        }

        $this->config = $config;

        // Registrar zona horaria
        if (isset($this->config['timezone'])) {
            date_default_timezone_set($this->config['timezone']);
        }

        // Crear directorios de logs y tmp si no existen
        $this->ensureDirectories();

        // Registrar oyente global en el EventBus que reenvía eventos al EventRouter
        $this->eventBus->subscribe('*', function (array $event) {
            $this->eventRouter->dispatch($event);
        });

        $this->booted = true;

        $this->log("Motor JAH inicializado correctamente (v{$this->config['version']})", 'info');

        // Levantar agentes configurados para arrancar al inicio
        $this->bootDefaultAgents();

        $this->eventBus->publish('system.boot', ['timestamp' => time()]);
    }

    /**
     * Registra e inicializa un agente.
     */
    public function registerAgent(string $name, BaseAgent $agent): void
    {
        $this->agents[$name] = $agent;
        $agent->boot($this);
        $this->log("Agente registrado e inicializado: {$name}", 'debug');
    }

    /**
     * Obtiene un agente registrado por su nombre.
     */
    public function getAgent(string $name): ?BaseAgent
    {
        return $this->agents[$name] ?? null;
    }

    /**
     * Desconecta y limpia todos los recursos del motor.
     */
    public function shutdown(): void
    {
        if (!$this->booted) {
            return;
        }

        $this->eventBus->publish('system.shutdown', ['timestamp' => time()]);

        foreach ($this->agents as $name => $agent) {
            try {
                $agent->shutdown();
                $this->log("Agente detenido: {$name}", 'debug');
            } catch (\Throwable $e) {
                $this->log("Error al apagar agente {$name}: " . $e->getMessage(), 'error');
            }
        }

        $this->agents = [];
        $this->booted = false;
        $this->log("Motor JAH detenido.", 'info');
    }

    public function getEventBus(): EventBus
    {
        return $this->eventBus;
    }

    public function getEventRouter(): EventRouter
    {
        return $this->eventRouter;
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Escribe un mensaje de log en el destino configurado.
     */
    public function log(string $message, string $level = 'info'): void
    {
        if (!($this->config['log']['enabled'] ?? false)) {
            return;
        }

        $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
        $configLevel = $this->config['log']['level'] ?? 'info';

        if (($levels[$level] ?? 1) < ($levels[$configLevel] ?? 1)) {
            return;
        }

        $logFile = $this->config['log']['file'] ?? dirname(__DIR__) . '/logs/jah.log';
        $formatted = sprintf(
            "[%s] [%s] %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message
        );

        file_put_contents($logFile, $formatted, FILE_APPEND);
    }

    private function bootDefaultAgents(): void
    {
        $bootList = $this->config['agents']['boot_on_start'] ?? [];
        foreach ($bootList as $agentClass) {
            $fullClass = "\\Jah\\Agents\\" . $agentClass;
            if (class_exists($fullClass)) {
                $agent = new $fullClass();
                $this->registerAgent($agentClass, $agent);
            } else {
                $this->log("No se pudo pre-cargar el agente: {$fullClass} (Clase no encontrada)", 'warning');
            }
        }
    }

    private function ensureDirectories(): void
    {
        $paths = $this->config['paths'] ?? [];
        foreach (['logs', 'tmp', 'cache'] as $key) {
            if (isset($paths[$key]) && !is_dir($paths[$key])) {
                mkdir($paths[$key], 0775, true);
            }
        }
    }
}
