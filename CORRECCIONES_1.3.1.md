# JAS 1.3.1 — correcciones de estabilidad

Esta revisión corrige errores detectados en rutas operativas que no estaban cubiertas por la suite anterior.

## Correcciones

- `VERSION` actualizado de `1.2.0` a `1.3.1`.
- TLS real para servidor y cliente: cuando TLS está activo, `tcp://` se normaliza a `tls://` y se validan certificado, clave y CA.
- El servidor TCP ya no oculta silenciosamente todos los errores; intenta responder con un error de protocolo y limpia conexiones de forma segura.
- La sincronización registra el heartbeat del nodo local.
- Se añadieron cursores persistentes por nodo y stream; ya no se exporta todo desde secuencia cero en cada ciclo.
- Los cursores nunca retroceden y se publican mediante escritura atómica.
- La replicación ahora mantiene una cadena independiente por `node_id + stream`.
- Se corrigió la mezcla de eventos de varios nodos dentro del mismo stream, que podía invalidar la secuencia y `prev_hash`.
- La importación rechaza huecos de secuencia, hashes inválidos y filas incompletas.
- La exportación devuelve por defecto la cadena originada por el nodo remoto solicitado, evitando reenvíos circulares.
- La CLI muestra arreglos legibles en lugar de cadenas internas `JAHPS1` para estado, métricas, WAL y snapshots.
- Se agregó una prueba de regresión para replicación multi-origen, cursores y servidor TCP.

## Validación

- 100 archivos PHP validados con `php -l`.
- Suite completa: PASS.
- Prueba TCP persistente: PASS.
- Prueba TLS con certificado autofirmado: PASS.
- Replicación de dos orígenes en un mismo stream: PASS.
