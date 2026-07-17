# Organización por dominios en JAS

Una aplicación JAS grande se divide en dominios con responsabilidad y prefijo de
acciones propios. Las dependencias entre dominios son explícitas y no pueden formar
ciclos.

```php
use Jah\JAS\Definition\ApplicationDefinition;

$app = (new ApplicationDefinition('Red Social Nacional'))
    ->domain('Identidad', 'identidad')
    ->domain('Publicaciones', 'publicacion', ['Identidad'])
    ->domain('Feeds', 'feed', ['Publicaciones'])
    ->action('Identidad', 'identidad.usuario.registrar')
    ->action('Publicaciones', 'publicacion.crear')
    ->action('Feeds', 'feed.distribuir');

$app->validate();
```

## Contratos de acción

Una acción destinada a producción declara entrada, salida, capacidad y auditoría.
Las operaciones transaccionales o encoladas deben ser idempotentes:

```php
$app->defineAction('Publicaciones', 'publicacion.crear')
    ->input('NuevaPublicacion')
    ->output('PublicacionCreada')
    ->requires('publicaciones.create')
    ->audit()
    ->transactional()
    ->idempotent()
    ->emits('publicacion.creada');

$app->defineAction('Feeds', 'feed.distribuir')
    ->input('PublicacionCreada')
    ->output('DistribucionAceptada')
    ->requires('feeds.distribute')
    ->audit()
    ->idempotent()
    ->queued('feeds', 'autor_id', 5);

$app->validateForProduction();
```

`validateForProduction()` rechaza acciones sin contrato, tipo, permiso o auditoría.
También impide que una acción transaccional o reintentable carezca de idempotencia.
Así, la forma corta de escribir JAS coincide con la forma segura.

Esta definición impide que Identidad dependa accidentalmente de Publicaciones o
que Feeds registre una acción bajo el prefijo `publicacion`. Antes de permitir una
llamada entre acciones puede comprobarse el límite:

```php
$app->assertCallAllowed(
    'publicacion.crear',
    'identidad.usuario.registrar'
);
```

## Regla de sostenibilidad

- Un dominio posee sus acciones y estado.
- Otro dominio sólo accede mediante acciones o eventos declarados.
- Las dependencias forman un grafo dirigido sin ciclos.
- Los trabajos secundarios se envían a colas y deben ser idempotentes.
- Las lecturas masivas usan proyecciones específicas, no consultas cruzadas entre
  todos los dominios.

Para una red social, Publicaciones confirma la escritura principal y emite eventos;
Feeds, Moderación, Notificaciones, Búsqueda y Métricas trabajan de forma independiente.
El backpressure de cada cola evita que una sobrecarga derribe toda la aplicación.

## Consumidores idempotentes

Cada consumidor conserva un cursor y un recibo por `event_id`. Si el proceso cae
después de registrar el recibo pero antes de avanzar el cursor, la recuperación no
repite el handler. Para efectos en bases externas, el handler debe usar `event_id`
como clave única o confirmar efecto y recibo dentro de la misma transacción. Ningún
runtime puede prometer exactamente-una-vez entre almacenamientos independientes sin
esa coordinación.
