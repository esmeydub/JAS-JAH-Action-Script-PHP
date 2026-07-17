# Plan maestro de JAS

Estado: **activo y normativo**
Producto: **JAS — Jah ActionScript**
Objetivo: capa organizada, tipada y segura sobre PHP para aplicaciones web empresariales y gubernamentales sostenibles, con DataCore como base de datos nativa.

## Reglas de ejecución

1. Las fases se realizan en orden. No se inicia una fase mientras la anterior no cumpla todos sus criterios de salida.
2. Cada cambio debe conservar PHP puro, `strict_types=1`, formatos nativos JAH/PHP y la prohibición de JSON en el runtime.
3. DataCore es la base de datos oficial. No se añadirá otra base como dependencia del núcleo.
4. Seguridad, tipado, auditoría y organización deben estar habilitados por definición, no depender de disciplina manual.
5. Toda función nueva requiere pruebas positivas, negativas y de recuperación proporcionales al riesgo.
6. La suite completa, lint y health deben pasar antes de cerrar una fase.
7. Ninguna documentación puede prometer una garantía que no esté implementada y probada.
8. Las APIs heredadas se eliminan o aíslan; el código nuevo sólo usa APIs oficiales de `src/JAS` y `src/DataCore`.
9. Los cambios incompatibles requieren manifiesto, reporte de compatibilidad y nota de migración.
10. Una auditoría o certificación externa nunca se sustituye por una afirmación del proyecto.

## Definición de terminado

JAS 2.0 se considerará terminado cuando:

- Las fases 1 a 10 estén cerradas.
- No existan pendientes críticos o altos conocidos.
- La API pública estable esté documentada.
- Las pruebas de caída, concurrencia, seguridad, carga, backup y restauración pasen.
- Exista una aplicación de referencia completa construida únicamente con APIs públicas JAS.
- El repositorio pueda instalarse, analizarse, probarse, operarse y actualizarse siguiendo documentación reproducible.

---

## Fase 1 — Consolidación del núcleo

Estado: **completada**

### Alcance

- Inventariar APIs actuales y clasificarlas como estable, experimental o heredada.
- Integrar o retirar `php_actionscript_php_doc` como fuente duplicada.
- Renombrar `MemoryActionScript` y eliminar identidad heredada restante.
- Normalizar namespaces, autoload y estilo.
- Formatear clases antiguas comprimidas.
- Eliminar comandos destructivos y limpieza mediante shell dentro de pruebas.
- Consolidar versión, documentación arquitectónica y mensajes de error.

### Criterios de salida

- Un solo motor de tipos y un solo runtime oficial.
- Cero referencias heredadas en APIs nuevas.
- Cero PHP comprimido de difícil revisión en el núcleo estable.
- Cero `exec`, `shell_exec`, `system`, `passthru` o `rm -rf` en runtime y pruebas.
- Analizador JAS, lint y suite completa en PASS.

### Evidencia de cierre

- API inventariada en `docs/API_STATUS.md`.
- `TypeRegistry`, `ActionScript` y los runtimes oficiales residen bajo `src/JAS`.
- La identidad `MemoryActionScript` fue retirada y `php_actionscript_php_doc` quedó aislado del autoload.
- Barrido del núcleo y pruebas: cero comandos de shell prohibidos y cero bloques PHP mayores de 180 caracteres.
- `bin/jas health`: capacidades obligatorias disponibles; conectores externos deshabilitados.
- `bin/jas analyze` sobre proyecto oficial generado: `PASS (4 files)`.
- Lint de `app`, `src`, prototipos, `public` y `tests`: PASS.
- Suite completa: PASS; fuzz rechazó 500 corrupciones y las pruebas DataCore, seguridad y empresa pasaron.

---

## Fase 2 — DataCore transaccional y recuperable

Estado: **completada**

### Alcance

- Añadir aislamiento de lectura para transacciones `PREPARED`.
- Garantizar visibilidad sólo después de `COMMITTED`.
- Integrar estado, outbox, auditoría e idempotencia en recuperación coordinada.
- Ordenar bloqueos para evitar deadlocks.
- Detectar y recuperar commits parciales.
- Completar compactación con manifiesto de recuperación tras caída.
- Bloquear compactación cuando existan transacciones pendientes.

### Criterios de salida

- Ningún lector observa un lote parcialmente aplicado.
- Pruebas de caída en cada punto de commit pasan.
- Recuperar dos veces no duplica efectos.
- Compactación interrumpida restaura o completa sin pérdida.
- Legal hold conserva todas las versiones exigidas.

### Evidencia de cierre

- Las inserciones, actualizaciones y eliminaciones `PREPARED` no sustituyen la vista confirmada.
- Pruebas de caída después de preparación, primera operación, lote aplicado y confirmación: PASS.
- Diagnóstico distingue `prepared`, `partially_applied` y `fully_applied_uncommitted`.
- Dos procesos confirman lotes concurrentes usando bloqueo interproceso reentrante: PASS.
- Recuperar dos veces conserva el conteo físico y no repite outbox, auditoría ni resultados idempotentes.
- `RecoveryCoordinator` recupera primero estado DataCore y después efectos gobernados.
- Compactación con transacciones pendientes es rechazada; una publicación parcial se revierte desde manifiesto.
- Compactación conserva el histórico sujeto a legal hold.
- Health, lint y suite completa: PASS.

---

## Fase 3 — DataCore empresarial

Estado: **completada**

### Alcance

- Índices únicos, compuestos, parciales, por rango y fecha.
- Constraints declarativos y relaciones por identificador.
- Consultas con límite obligatorio y rechazo de scans accidentales.
- Reindexación online.
- Migraciones con rollback lógico y compatibilidad.
- Claves por sujeto o partición y destrucción criptográfica.
- Retención, legal hold y evidencia de borrado.
- Adaptador opcional DataCore SQL Mirror para sincronizar con bases SQL empresariales.
- DataCore será siempre la puerta de entrada: validará, cifrará, autorizará y auditará antes de replicar.
- Outbox durable para exportación SQL, cursor de importación y reintentos idempotentes.
- Modos configurables `datacore-primary`, espejo de sólo lectura y migración controlada desde SQL.
- Detección explícita de divergencias, cuarentena de conflictos y reconciliación auditada.
- Control de exposición por campo para impedir que secretos o datos cifrados terminen en columnas inseguras.
- Adaptadores SQL fuera del núcleo; PDO será el contrato opcional y DataCore no dependerá de un motor específico.
- Frontera SQL no confiable y unidireccional por defecto; una alteración del espejo nunca se replica hacia DataCore.
- Outbox firmado, allowlist fija de esquema, sentencias preparadas, límites, cuarentena y circuit breaker.
- Importación SQL sólo como migración gobernada de sólo lectura, con doble control y sin ejecución de contenido.

### Criterios de salida

- Conflictos únicos y constraints se rechazan concurrentemente.
- Consultas críticas usan índice verificable.
- Destruir una clave vuelve irrecuperable el histórico correspondiente.
- Migraciones fallidas recuperan el estado anterior.
- Benchmarks documentan límites reales.
- Una caída de SQL no bloquea escrituras confirmadas en DataCore y el espejo se recupera desde el outbox.
- Repetir una sincronización no duplica filas ni revierte versiones nuevas.
- Ninguna escritura SQL evita contratos, permisos, cifrado o auditoría de DataCore.
- Pruebas adversariales demuestran que filas, esquemas, credenciales y respuestas SQL comprometidas no contaminan DataCore.
- Reportes comparativos publican latencia, RAM, CPU y almacenamiento sin afirmar ventajas no medidas.

### Evidencia de cierre

- Índices exactos, compuestos, únicos, parciales y de rango persisten en journals físicos; conflictos únicos concurrentes se rechazan bajo bloqueo global.
- `query()` rechaza scans implícitos; `scan()` los declara de forma explícita y las rutas críticas exponen plan `secondary_exact` o `secondary_range`.
- Reindexación publica una generación atómica mientras las lecturas conservan el índice anterior.
- Constraints, referencias restrictivas y migraciones reversibles/compatibles cuentan con pruebas positivas, negativas y rollback tras fallo parcial.
- `SubjectKeyVault` cifra por sujeto, destruye la clave individual sin afectar otros sujetos y firma evidencia verificable de destrucción.
- Retención auditable conserva legal hold; compactación conserva todas las versiones retenidas.
- SQL Mirror usa PDO preparado, allowlist, proyección segura, versiones monotónicas, outbox completamente firmado, reintentos, cuarentena y circuit breaker.
- Reconciliación detecta ausencia, atraso, adelanto no confiable y divergencia; `SqlMirrorAuditJournal` firma evidencia y rechaza alteraciones.
- `GovernedSqlImporter` está deshabilitado por defecto y sólo opera con modo tipado, cursor, límite, lote prevalidado y doble control consumible.
- Pruebas adversariales confirman que inyección, filas inválidas, replay, manipulación SQL y outbox/auditoría alterados no contaminan DataCore.
- Benchmark reproducible y línea base honesta en `docs/DATACORE_BENCHMARKS.md`; mide latencia, CPU, memoria y disco y publica que SQLite gana la microprueba actual.
- Caché de journal de índice redujo localmente 1,000 búsquedas DataCore de ~1,623 ms a ~280 ms sin omitir validación transaccional.
- Health, lint completo y suite completa: PASS; fuzz rechazó 500 corrupciones.

---

## Fase 4 — Backup, restauración y continuidad

Estado: **completada**

### Alcance

- Snapshots consistentes de DataCore, journals, índices y key metadata.
- Backup cifrado y firmado.
- Manifiesto con hashes y versión.
- Restauración completa y point-in-time.
- Políticas de retención de respaldos.
- Simulación de desastre y restauración automatizada.

### Criterios de salida

- Backup alterado es rechazado.
- Restauración en directorio vacío reproduce documentos, índices y journals.
- Prueba point-in-time pasa.
- Procedimiento de recuperación está documentado y medido.

### Evidencia de cierre

- `DataCoreContinuityLock` coordina escritores compartidos y snapshot exclusivo; almacenamiento, transacciones, outbox y bóveda de claves aceptan el mismo coordinador.
- `DataCoreBackupService` fuerza flush dentro de la ventana consistente y captura recursivamente segmentos, índices, journals y metadata bajo una raíz institucional.
- El formato nativo `.jahb` cifra cada archivo por propósito/ruta y firma el manifiesto versionado con hashes, tamaños e instante real.
- Symlinks, traversal, fuente inexistente, backup dentro de la fuente, archivos fuera de límite y destinos no vacíos se rechazan.
- Restauración valida firma, descifra y verifica cada entrada antes de publicar desde staging; una base restaurada se abre y consulta con `DataCoreTurbo`.
- Point-in-time selecciona el snapshot íntegro más reciente anterior al instante pedido; su granularidad real de snapshot está documentada sin prometer replay WAL fino.
- Retención conserva un mínimo de copias recientes, usa `dry-run` por defecto y no elimina automáticamente archivos corruptos.
- La prueba de desastre automatizada altera un archivo, confirma rechazo, restaura en árbol vacío y valida datos e índices.
- Benchmark local reproducible de 5,000 registros: creación 0.068148 s, verificación 0.031700 s, restauración 0.109296 s; lectura restaurada PASS.
- Health, lint y suite completa, incluida `DATACORE BACKUP`, en PASS.

---

## Fase 5 — Identidad y acceso institucional

Estado: **en progreso**

### Alcance

- Usuarios, roles, permisos y sesiones almacenados cifrados en DataCore.
- MFA TOTP y códigos de recuperación.
- Passkeys/WebAuthn como módulo opcional.
- Sesiones y dispositivos visibles y revocables.
- Roles temporales, delegación y expiración.
- Separación de funciones y doble control integrado con acciones.
- Credenciales de servicio rotables.
- Adaptadores opcionales OIDC/SAML/LDAP fuera del núcleo.

### Criterios de salida

- MFA, recuperación, revocación y expiración tienen pruebas negativas.
- Roles incompatibles no pueden asignarse.
- Operaciones críticas exigen dos identidades autorizadas.
- Cambios de permisos quedan auditados.

---

## Fase 6 — JAS Web completo

Estado: **pendiente**

### Alcance

- Grupos, prefijos y middleware por ruta.
- Cookies tipadas y seguras.
- Uploads con MIME real, tamaño, hash, cifrado y custodia.
- Componentes con layouts, slots, tablas, paginación y errores.
- Formularios con fechas, zona horaria, selects y archivos.
- Internacionalización.
- Accesibilidad WCAG 2.2 AA verificable.
- Streaming y descargas autorizadas.

### Criterios de salida

- Suite XSS/CSRF/header/upload pasa.
- Navegación completa funciona sin HTML manual inseguro.
- Auditoría de accesibilidad de la aplicación de referencia pasa.
- Todos los endpoints atraviesan contratos gobernados.

---

## Fase 7 — Herramientas y experiencia de desarrollo

Estado: **pendiente**

### Alcance

- Generadores completos y actualización segura de definiciones.
- Formateador oficial.
- Analizador con resolución de namespaces y flujo entre dominios.
- Integración PHPStan.
- LSP: diagnósticos, hover, definición, referencias y rename.
- Documentación y diagramas generados.
- Verificación de compatibilidad en CLI.

### Criterios de salida

- Proyecto nuevo llega a aplicación funcional siguiendo una sola guía.
- LSP opera sobre tipos, acciones, eventos y capacidades.
- Renombrado conserva referencias.
- CI rechaza contratos rotos y dependencias de dominio ilegales.

---

## Fase 8 — Escala y operación

Estado: **pendiente**

### Alcance

- Dead-letter queues y reproceso con doble control.
- Liveness, readiness y health HTTP.
- Alertas y límites de disco.
- Retención y compactación automática de logs/journals.
- Métricas y trazas exportables mediante adaptadores.
- Balanceo, sharding y backpressure probados.
- Panel operativo seguro.

### Criterios de salida

- Saturar un consumidor no derriba otros dominios.
- DLQ conserva contexto y permite reproceso idempotente.
- Alertas se activan antes de agotar disco o leases.
- Operación de siete días bajo carga sostenida sin corrupción.

---

## Fase 9 — Verificación de seguridad y fallos

Estado: **pendiente**

### Alcance

- Fuzzing continuo de JASB, DataCore y formularios.
- Property tests ampliados.
- Apagado forzado en todos los puntos críticos.
- Pruebas multiproceso y multinodo reales.
- Particiones, latencia y pérdida de red.
- Rotación de claves bajo carga.
- Threat model y checklist OWASP ASVS.
- Revisión criptográfica y penetration test externos.

### Criterios de salida

- Cero hallazgos críticos o altos abiertos.
- Evidencia reproducible de recuperación ante fallos.
- Límites de seguridad y supuestos documentados.
- Informe externo registrado sin presentarlo como certificación universal.

---

## Fase 10 — Aplicación de referencia y estabilización 2.0

Estado: **pendiente**

### Alcance

- Construir una red social/portal gubernamental de referencia.
- Dominios: identidad, usuarios, publicaciones, feeds, mensajería, moderación, notificaciones y auditoría.
- Usar sólo APIs públicas estables.
- Pruebas end-to-end, carga, backup, restauración y actualización.
- Congelar API 2.0 y publicar guía de migración.
- Marcar claramente módulos experimentales.

### Criterios de salida

- Aplicación desplegable y reproducible.
- Ningún acceso directo rompe límites de dominio.
- Feed, moderación y notificaciones escalan independientemente.
- Documentación de instalación, desarrollo, operación y desastre completa.
- Checklist de definición de terminado satisfecho.

---

## Registro de avance

Cada cierre debe añadirse aquí con fecha, evidencia y comandos ejecutados.

| Fase | Estado | Evidencia |
|---|---|---|
| 1 | En progreso | Motor de tipos unificado, suite y analizador existentes |
| 2 | Pendiente | — |
| 3 | Pendiente | — |
| 4 | Pendiente | — |
| 5 | Pendiente | — |
| 6 | Pendiente | — |
| 7 | Pendiente | — |
| 8 | Pendiente | — |
| 9 | Pendiente | — |
| 10 | Pendiente | — |

## Próxima acción obligatoria

Completar **Fase 1 — Consolidación del núcleo**. No iniciar aislamiento transaccional de Fase 2 hasta cerrar y registrar todos sus criterios de salida.
