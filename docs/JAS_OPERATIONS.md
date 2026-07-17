# Operación segura de JAS

## Dead-letter queue

Un trabajo entra a la DLQ cuando agota sus intentos o un worker declara un fallo
terminal. La DLQ no es un almacén paralelo: es la vista de trabajos `failed` del
journal durable de la cola. Así conserva el payload, contrato, capacidad, error,
intentos y vínculo de deduplicación sin crear dos fuentes de verdad.

```bash
php bin/jas queue:dead 100
```

Los trabajos fallidos sobreviven a `queue:compact`, incluso cuando se solicita
descartar otros estados terminales.

## Reproceso con doble control

El reproceso exige solicitud, aprobación por otra identidad y consumo ligado a
la huella completa del trabajo fallido:

```bash
php bin/jas queue:reprocess:request JOB_ID operador.uno req-dlq-0001
php bin/jas queue:reprocess:approve APROBACION_ID supervisor.uno
php bin/jas queue:reprocess JOB_ID APROBACION_ID req-dlq-0001 supervisor.uno
```

La huella incluye ID, acción, payload, capacidad, prioridad, límites, intentos,
objeto, deduplicación, error y estado. Cambiar el trabajo, el request ID o el
actor invalida la autorización. El aprobador no puede ser el solicitante.

Cada aprobación crea como máximo un trabajo nuevo. Las llamadas repetidas
devuelven el mismo ID; el nuevo trabajo conserva `originJobId` y
`reprocessApprovalId`, reinicia sus intentos y vuelve a respetar capacidad y
backpressure. Si la aprobación se consume y la cola está llena, la operación se
puede retomar después sin solicitar otra aprobación.

El CLI debe ejecutarse dentro de un plano administrativo autenticado y con
permisos mínimos del sistema operativo. Los identificadores escritos en el CLI
son atribución de auditoría, no sustituyen la autenticación institucional del
operador. Una interfaz remota debe resolver las identidades con
`InstitutionalIdentityService` antes de invocar `DeadLetterService`.

Las operaciones confirmadas se registran en `AuditJournal`; las métricas
`queue.dead_letter.reprocess_requested` y `queue.dead_letter.reprocessed`
permiten alertar sobre volumen o abuso.

## Liveness, readiness y health HTTP

El plano operativo está disponible mediante `public/health.php` y siempre
responde como texto PHP/JAS nativo, nunca JSON. En producción, el proxy debe
mapear las rutas canónicas al mismo script:

```text
GET /health/live
GET /health/ready
GET /health
```

La llamada directa `GET /health.php/live` y `GET /health.php/ready` también es
válida cuando no existe rewrite. Todas las respuestas incluyen `no-store`,
`nosniff`, protección contra frames, CSP y políticas restrictivas.

- `/health/live` sólo demuestra que PHP puede responder. No abre DataCore, no
  consulta colas y no debe reiniciar un proceso por una dependencia caída.
- `/health/ready` ejecuta las comprobaciones de PHP, runtime, DataCore y espacio
  libre. Devuelve `200 JAS READY` o `503 JAS NOT READY`, sin nombres internos,
  rutas, excepciones ni tiempos. Un 503 incluye `Retry-After`.
- `/health` expone el estado individual y duración de cada comprobación sólo a
  operadores autenticados. Requiere `Authorization: Bearer <token>` y
  `JAS_HEALTH_TOKEN` de al menos 32 bytes; si falta o el autorizador falla,
  responde 401 antes de ejecutar las comprobaciones.

El umbral preventivo se configura con `JAS_HEALTH_MIN_FREE_BYTES`; el mínimo
aceptado es 16 MiB y el valor predeterminado es 256 MiB. Este umbral sólo marca
readiness: las alertas y límites progresivos de disco se implementan en el
siguiente bloque de la Fase 8.

No se debe usar `/health` como sonda pública ni incluir el token en URLs, logs o
manifiestos versionados.
