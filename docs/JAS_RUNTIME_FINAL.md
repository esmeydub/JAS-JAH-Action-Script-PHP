# JAS Runtime unificado

JAS convierte acciones y objetos definidos desde PHP en una ejecución orientada a eventos y grafos. PHP sigue siendo el núcleo obligatorio; los adaptadores nativos son opcionales.

## Flujo ejecutable

1. Una aplicación define objetos activos, eventos y acciones.
2. `GraphScheduler` libera nodos cuando sus dependencias terminan.
3. `JasRuntime` valida la capacidad SALK y abre una entrada WAL.
4. La acción se ejecuta; al éxito, el WAL se confirma.
5. `ObjectRuntime` aplica el parche de estado con control de versión.
6. `ObjectStateStore` persiste el objeto mediante DataCore y memoria piramidal.
7. `BinaryRuntime` expone la misma operación a C, C++ u otros runtimes mediante JASB v2.

## Outbox recuperable

`GovernedRuntime` persiste un registro `PREPARED` antes de publicar evento y
auditoría. Cuando ambos se confirman, escribe `APPLIED`. Si PHP se detiene entre
ambos puntos, `recoverOutbox()` vuelve a publicar. EventJournal y AuditJournal
deduplican por request ID, por lo que la recuperación es repetible.

```php
$recovered = $runtime->recoverOutbox();
```

El outbox coordina los journals nativos de JAS. Si el handler escribe en una base
de datos externa, debe usar una transacción/outbox de esa base o una operación
idempotente; JAS no puede crear atomicidad física entre almacenamientos ajenos.

## Garantías implementadas

- Paquetes binarios independientes del lenguaje.
- HMAC SALK sobre cabecera, identidad y payload.
- Ventana temporal y protección contra replay.
- Capacidades por principal y operación.
- WAL con BEGIN, COMMIT y ABORT.
- Restauración de objetos desde DataCore.
- Control optimista de versión de objetos.
- Detección de ciclos y fallos de dependencias.
- Registro de workers por capacidad, carga y heartbeat.

## Límites honestos

- Fibers ofrecen concurrencia cooperativa, no paralelismo multinúcleo.
- El SDK C incluido decodifica el protocolo; cada runtime nativo debe implementar la verificación HMAC SALK con una biblioteca criptográfica auditada.
- La distribución en varios servidores requiere transporte, consenso y replicación adicionales; no se afirma que ya existan.
