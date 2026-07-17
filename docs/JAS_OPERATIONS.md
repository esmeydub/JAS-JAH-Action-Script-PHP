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

Los umbrales se configuran con `JAS_DISK_WARNING_BYTES`,
`JAS_DISK_CRITICAL_BYTES` y `JAS_DISK_EMERGENCY_BYTES`. Sin configuración, JAS
adapta los valores al tamaño del volumen: 20 %, 10 % y 3 %, limitados a un
máximo de 2 GiB, 1 GiB y 256 MiB y a mínimos seguros de 64, 32 y 16 MiB. Esto
evita aplicar umbrales de un servidor grande a volúmenes pequeños de pruebas o
contenedores.

No se debe usar `/health` como sonda pública ni incluir el token en URLs, logs o
manifiestos versionados.

## Presión de disco y admisión de escrituras

```bash
php bin/jas disk:status
```

`DiskPressureGuard` aplica cuatro niveles ordenados:

- `normal`: admite todas las escrituras.
- `warning`: emite una transición de alerta, mantiene el servicio y permite
  actuar antes de afectar tráfico.
- `critical`: readiness falla y se rechazan escrituras regulares nuevas, como
  submits de cola, logs informativos y flushes DataCore. Se conserva una reserva
  para completar, fallar o cancelar leases y registrar operaciones esenciales.
- `emergency`: se rechazan también escrituras esenciales antes de consumir el
  último espacio recuperable.

La evaluación considera el tamaño estimado de la operación: una escritura que
cruzaría preventivamente a `critical` o `emergency` se rechaza aunque el estado
actual todavía sea menos severo. DataCore conserva en memoria un lote cuyo flush
fue rechazado y puede publicarlo después de recuperar espacio.

La alerta se entrega mediante un callback tipado sólo cuando cambia el nivel;
un proceso persistente no repite la misma alerta en cada evaluación y también
emite la transición de recuperación a `normal`. El adaptador de alertas externo
debe enviarla al sistema institucional sin escribir de nuevo en el mismo disco
afectado. `/health/ready` permite que el orquestador retire el nodo cuando llega
a `critical`, sin revelar métricas internas al público.
