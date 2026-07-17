# JAS Runtime 1.1 — ejecución duradera

Esta versión introdujo la base de ejecución duradera que continúa en JAS 1.4.0.

## Componentes

- Cola persistente con estados, prioridades y reintentos.
- Leases de trabajo y recuperación de ejecuciones abandonadas.
- Registro de workers y despacho basado en capacidades.
- WAL para recuperar operaciones que no llegaron a `commit` o `abort`.
- Métricas locales de cola, workers y ejecución.

## Operación

```bash
bin/jas health
bin/jas queue:stats
bin/jas queue:recover
bin/jas queue:compact
bin/jas wal:pending
bin/jas metrics
```

Los datos operativos se guardan bajo `runtime/`. Este directorio no debe compartirse
entre nodos mediante un sistema de archivos sin coordinación; la replicación JAS es
la responsable de intercambiar eventos entre identidades del clúster.

## Garantías y límites

El WAL registra intención y resultado, pero un handler recuperado puede ejecutarse
de nuevo. Las acciones con efectos externos deben ser idempotentes y usar
`_request_id` como clave de deduplicación. Los leases ofrecen entrega al menos una
vez, no exactamente una vez.
