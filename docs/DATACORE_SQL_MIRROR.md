# DataCore SQL Mirror

Estado: **implementado en la Fase 3**.

## Propósito

DataCore SQL Mirror permitirá a una organización conservar una copia compatible
en SQL mientras adopta DataCore. SQL no será una ruta alternativa de escritura ni
una dependencia del núcleo. Toda operación entrará por DataCore y atravesará sus
contratos de tipo, permisos, cifrado, auditoría y transacciones.

## Flujo gobernado

1. La aplicación envía una acción tipada a JAS.
2. DataCore valida y confirma la transacción local.
3. La misma confirmación genera un evento durable en el outbox SQL.
4. Un worker opcional transforma únicamente los campos autorizados.
5. El adaptador ejecuta un `upsert` idempotente mediante PDO.
6. DataCore registra cursor, versión, hash y resultado de sincronización.
7. Las divergencias entran en cuarentena; nunca se resuelven sobrescribiendo en silencio.

## Garantías implementadas

- DataCore continúa operando cuando SQL está caído.
- El orden y las versiones proceden de DataCore.
- Cada fila SQL conserva identificador, versión DataCore y hash de reconciliación.
- Los campos cifrados permanecen cifrados salvo una política explícita y auditable.
- Importar desde SQL sólo se permite mediante `GovernedSqlImporter`, en modo
  `governed-sql-migration`, con cursor, límite, allowlist y doble control consumible.
- Los conectores específicos se distribuyen fuera del runtime principal.

## Frontera de seguridad

SQL se considera un sistema externo no confiable. Comprometer el servidor SQL,
sus credenciales, sus tablas o el proceso de sincronización no concede autoridad
sobre DataCore.

- El modo normal es estrictamente unidireccional: DataCore → SQL.
- Las credenciales SQL sólo reciben permisos sobre tablas espejo; nunca sobre archivos,
  claves, journals, sockets administrativos o procesos de DataCore.
- El worker consume eventos firmados del outbox y rechaza eventos alterados, repetidos,
  vencidos o fuera de secuencia.
- Las consultas utilizan sentencias PDO preparadas y una lista fija de tablas y columnas;
  ningún identificador SQL procede directamente de datos del usuario.
- Los valores SQL nunca se deserializan como objetos PHP ni se ejecutan como tipos,
  acciones, migraciones o configuración JAS.
- Borrar o modificar una fila SQL sólo genera una divergencia auditada; no propaga el
  borrado ni la modificación hacia DataCore.
- Un volumen anormal de errores, conflictos o respuestas activa circuit breaker y pone
  el espejo en cuarentena sin detener DataCore.
- Los secretos maestros, claves de cifrado e integridad nunca se entregan al adaptador SQL.
- La reconciliación compara identificador, versión y hash firmado desde DataCore.
- Cada reconciliación puede producir evidencia firmada verificable mediante
  `SqlMirrorAuditJournal`.
- La importación desde SQL permanece deshabilitada por defecto y sólo puede ejecutarse
  como migración separada, de sólo lectura, con esquema permitido, límites, doble control
  y escritura mediante las API públicas de DataCore.

## Modelo frente a ataques

| Ataque desde SQL | Respuesta obligatoria |
|---|---|
| Inyección o dato malicioso | Sentencia preparada, contrato de campo y rechazo |
| Fila alterada | Divergencia; DataCore no cambia |
| Tabla borrada | Reconstrucción desde outbox/snapshot autorizado |
| Credenciales robadas | Alcance sólo espejo y rotación inmediata |
| Repetición de eventos | Idempotencia por operación y versión |
| Respuesta masiva o lenta | Límites, timeout, backpressure y circuit breaker |
| Intento de importar comandos | Datos tratados como datos; nunca ejecución |

## Posicionamiento honesto

El espejo reduce el riesgo percibido de adopción y facilita reportes o integraciones
existentes. Las ventajas de velocidad, memoria, CPU y almacenamiento sólo podrán
publicarse después de benchmarks reproducibles con volúmenes y hardware documentados.
La línea base actual está publicada en `docs/DATACORE_BENCHMARKS.md` y, en esa prueba,
SQLite supera a DataCore; el proyecto no oculta ni invierte ese resultado.

## Modos tipados

- `SqlMirrorMode::DataCorePrimary`: modo normal; no permite importar desde SQL.
- `SqlMirrorMode::ReadOnlyMirror`: SQL funciona como copia de consulta sin autoridad.
- `SqlMirrorMode::GovernedSqlMigration`: habilita exclusivamente una migración aprobada.

No existe un modo bidireccional.

## Uso seguro de una migración

El operador configura en código la tabla, columnas y correspondencia de campos. Calcula
la huella mediante `approvalFingerprint()`, una identidad solicita la operación y otra
la aprueba en `DualControlStore`. La autorización sólo sirve una vez y únicamente para
la colección, mapping, cursor y límite exactos. Antes de la primera escritura, todo el
lote atraviesa tipos, constraints, referencias, identificadores y unicidad DataCore.

Los valores SQL sólo pueden ser escalares, tienen tamaño máximo, no se deserializan como
objetos y nunca se interpretan como PHP, JAS, configuración o nombres SQL.
