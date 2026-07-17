# Plan maestro de JAS

Estado: **activo y normativo**
Producto: **JAS — JAH Action Script PHP**
Objetivo: capa organizada, tipada y segura sobre PHP para aplicaciones web empresariales y gubernamentales sostenibles, con DataCore como base de datos nativa.

## Principio arquitectónico inmutable

El núcleo de JAS es 100% PHP puro. El sistema de tipos, las definiciones, las
rutinas, el runtime, DataCore y las herramientas oficiales forman un lenguaje
organizado dentro de PHP y no incorporan código JavaScript ni runtimes externos.

Otros lenguajes sólo pueden integrarse fuera del motor mediante adaptadores y el
protocolo binario JASB, siguiendo el mismo límite usado por los SDK C/C++. Una
integración externa nunca puede ejecutar código dentro del núcleo ni evitar
tipos, contratos, autorización, auditoría o la gobernanza de DataCore.

SALK aplica este límite como gate verificable y la suite registra
`JAS PURE PHP: PASS`; introducir un archivo JSON o JavaScript hace fallar la
comprobación del paquete.

## Reglas de ejecución

1. Las fases se realizan en orden. No se inicia una fase mientras la anterior no cumpla todos sus criterios de salida.
2. Cada cambio debe conservar PHP puro, `strict_types=1`, formatos nativos JAH/PHP y la prohibición de JSON en almacenamiento, protocolos y configuración operativa.
3. JAS no depende de archivos JSON, JavaScript, TypeScript, Node, npm, Composer ni artefactos frontend ejecutables; las herramientas PHP externas se ejecutan como PHAR verificados fuera del runtime.
4. Todo lenguaje externo se conecta fuera de proceso mediante JASB y adaptadores equivalentes a los SDK C/C++; no se incrusta dentro del motor.
5. DataCore es la base de datos oficial. No se añadirá otra base como dependencia del núcleo.
6. Seguridad, tipado, auditoría y organización deben estar habilitados por definición, no depender de disciplina manual.
7. Toda función nueva requiere pruebas positivas, negativas y de recuperación proporcionales al riesgo.
8. La suite completa, lint y health deben pasar antes de cerrar una fase.
9. Ninguna documentación puede prometer una garantía que no esté implementada y probada.
10. Las APIs heredadas se eliminan o aíslan; el código nuevo sólo usa APIs oficiales de `src/JAS` y `src/DataCore`.
11. Los cambios incompatibles requieren manifiesto, reporte de compatibilidad y nota de migración.
12. Una auditoría o certificación externa nunca se sustituye por una afirmación del proyecto.

## Definición de terminado

JAS 2.0 se considerará terminado cuando:

- Las fases 1 a 10 estén cerradas.
- La Puerta 8.5 del LSP estándar externo esté cerrada.
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

Estado: **completada**

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

### Evidencia de cierre

- `InstitutionalIdentityService` almacena usuarios, roles, asignaciones, sesiones, dispositivos, MFA y credenciales de servicio en colecciones tipadas DataCore.
- Nombre visible, contraseña, secreto TOTP, recuperaciones, permisos, incompatibilidades, identidad de sesión y secretos de servicio permanecen cifrados físicamente.
- Los tokens nunca se almacenan: IDs de sesión, desafío y lookup se derivan mediante HMAC con pepper institucional separado.
- MFA TOTP usa RFC 6238, alta temporal, desafío previo a crear sesión, ventana limitada y revocación de sesiones anteriores al activarlo.
- Códigos de recuperación se muestran una vez, se almacenan con `password_hash` y se eliminan después del primer uso; replay y expiración son rechazados.
- Dispositivos y sesiones son visibles al propietario, revocables y evaluados por expiración en cada acceso.
- Roles temporales y delegados vencen sin renovar sesión; revocaciones y cambios de permisos se reflejan dinámicamente.
- Separación de funciones comprueba incompatibilidad en ambos sentidos e impide combinar roles críticos.
- Solicitud y aprobación críticas exigen MFA, permisos separados, dos identidades y autorización consumible ligada a acción/request/huella.
- Credenciales de servicio se emiten una vez, rotan con versión monotónica e invalidan inmediatamente el secreto anterior.
- `AuthMiddleware` usa `AuthorizationProvider` dinámico y conserva compatibilidad explícita con `AuthStore` heredado.
- `WebAuthnAdapter` y `FederatedIdentityAdapter` definen extensiones opcionales; la criptografía concreta permanece fuera del núcleo para usar implementaciones auditadas.
- Cambios de usuario, rol, permiso, asignación, MFA, sesión, aprobación y credencial generan evidencia en `AuditJournal`.
- Pruebas negativas cubren bloqueo, contraseña, MFA inválido/vencido/replay, recuperación reutilizada, expiración, revocación, delegación, doble control y rotación.
- Health, lint y suite completa: PASS; `JAS INSTITUTIONAL IDENTITY: PASS`.

---

## Fase 6 — JAS Web completo

Estado: **completada**

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

### Avance verificado

- `Router` admite grupos anidados con prefijos estáticos, middleware heredable y middleware aislado por ruta.
- Las rutas agrupadas conservan nombres, generación segura de URL y metadatos de nombre, plantilla y acción durante la ejecución.
- Cada ruta sigue exigiendo una acción de `GovernedRuntime`; los grupos no crean un camino alterno al contrato.
- `SecureCookieJar` define tipos y vencimiento antes de emitir valores, cifra y autentica mediante `KeyRing` y rechaza alteración, cambio de propósito y expiración.
- Las cookies usan prefijo `__Host-`, `Path=/`, `Secure`, `HttpOnly` y `SameSite`; `Response` soporta múltiples encabezados `Set-Cookie` sin combinarlos.
- `UploadVault` estabiliza el archivo en staging privado, impone tamaño incremental, detecta MIME real, valida firmas conocidas y exige un escáner que falla cerrado.
- El contenido aprobado se cifra por bloques fuera del document root; DataCore conserva metadatos tipados y sensibles cifrados, mientras auditoría registra custodia y lectura.
- La lectura exige propietario y verifica autenticidad de bloques, cantidad, tamaño y SHA-256; contenido activo, traversal, corrupción y acceso cruzado se rechazan.
- `Layout` restringe slots a landmarks semánticos, exige contenido principal e incluye navegación para saltar bloques repetidos.
- `DataTable` exige caption y contrato completo de filas/columnas; produce encabezados accesibles y región navegable sin omitir escape por defecto.
- `Pagination` usa rutas con nombre, valida rangos y genera navegación acotada con estado actual y relaciones anterior/siguiente.
- `ErrorPage` publica mensajes permitidos para estados conocidos; el router ya no devuelve excepciones ni detalles internos en errores 400/404/422/500.
- `FormControl` añade fechas con rango, fecha/hora normalizada a UTC, zonas IANA, selects con allowlist y archivos ligados a `UploadPolicy`.
- `Form::submit()` verifica CSRF en profundidad, opciones simples/múltiples y archivos HTTP; el renderer genera multipart y relaciones accesibles de error.
- `TypeRegistry` reconoce `date`, `datetime` y `timezone` con validación estricta que rechaza normalización silenciosa de fechas imposibles.
- `TranslationCatalog` valida claves, placeholders y parámetros tipados; catálogos secundarios conservan el esquema del fallback y pueden ser parciales.
- `LocaleNegotiator` limita y analiza `Accept-Language` contra allowlist sin usar el locale como ruta; componentes y errores comparten un `Translator` con `es-MX` y `en-US` nativos.
- `AccessibilityAudit` reporta hallazgos estructurales por criterio WCAG 2.2 y exige evidencia separada para contraste, reflow, teclado, foco, objetivos, autenticación y lector de pantalla.
- La aplicación de referencia usa landmarks JAS y pasa la auditoría automatizada; un documento adversarial confirma detección de fallas.
- `Response::stream()` conserva el productor a través de headers, cookies y middleware y prohíbe consumirlo dos veces.
- `UploadVault` autoriza propietario o `UploadAccessPolicy`, prevalida custodia y transmite bajo el mismo bloqueo en bloques de hasta 64 KiB; headers de descarga y auditoría se generan de forma controlada.
- `Router::dispatchGlobals()` encapsula el borde HTTP: entradas malformadas reciben 400 y fallos inesperados 500, siempre con headers seguros y sin exponer excepciones.
- La revisión real en Chromium 149 y Orca 50.1.2/AT-SPI verifica contraste, reflow a 320 CSS px, teclado, foco visible y no oculto, objetivos y semántica anunciada. El caso de autenticación es no aplicable porque la referencia es pública y de solo lectura.
- Resultados, entorno, limitaciones, extracto sanitizado de lector de pantalla y huellas SHA-256 están conservados en `docs/JAS_WEB_ACCESSIBILITY_EVIDENCE.md`.
- Todo el alcance funcional y los criterios de salida de JAS Web están implementados y verificados.
- Pruebas positivas y negativas de grupos, middleware, cookies, uploads, streaming, componentes, formularios, i18n y accesibilidad: PASS; `JAS ACCESSIBILITY: PASS`; `JAS I18N: PASS`; `JAS ADVANCED FORMS: PASS`; `JAS COMPONENTS: PASS`; `JAS UPLOAD CUSTODY: PASS`; suite completa: `JAS SUITE: PASS`.

---

## Fase 7 — Herramientas y experiencia de desarrollo

Estado: **completada**

### Avance verificado

- Los proyectos generados cargan definiciones PHP aisladas por orden determinista, sin JSON ni reescritura frágil de un archivo central.
- Tipos, dominios, eventos y acciones generados quedan conectados al `JasApplication`; `PhpDefinitionReader` interpreta sólo arrays literales sin ejecutar los archivos, los esquemas inesperados fallan cerrados y la validación de producción comprueba el grafo completo.
- `make:event` genera eventos versionados y `make:action` acepta entrada, salida y capacidad explícitas; la inferencia sólo se permite cuando existe un único tipo inequívoco.
- `DefinitionEditor` actualiza campos, dependencias y contratos con referencias validadas; `PhpDefinitionStore` usa bloqueo, temporal verificado, `fsync` y reemplazo atómico sin seguir symlinks.
- El formateador oficial produce una representación canónica de las definiciones y ofrece `--check` no mutante para CI; PHP de aplicación queda fuera de su superficie de reescritura.
- El analizador indexa símbolos `App\\`, comprueba rutas PSR-4, resuelve imports internos y rechaza flujos entre `App\\Domains\\<Dominio>` cuando la dependencia no está declarada.
- `analyze` reconstruye el grafo de producción mediante el lector literal seguro, por lo que contratos, tipos, eventos o dependencias rotos hacen fallar CI sin ejecutar definiciones.
- PHPStan 2.1.56 está integrado como PHAR externo con SHA-256 fijado, en nivel 5 para todo `src/JAS` y `src/DataCore`, sin Composer ni baseline; el workflow valida PHP 8.2/8.4 antes de aceptar cambios.
- `JAS Language Intelligence Engine` ofrece por CLI diagnósticos, hover, definición, referencias y rename para tipos, dominios, acciones, eventos y capacidades; trabaja sobre PHP literal sin ejecutar código ni persistir JSON.
- El proyecto no afirma compatibilidad con el Language Server Protocol estándar: no incorpora JSON-RPC por stdio, ciclo `initialize`, sincronización de documentos abiertos ni integración directa con editores.
- El rename usa vista previa, validación por clase de símbolo, detección de colisiones, hashes contra cambios concurrentes, bloqueo y reemplazo recuperable de todas las referencias; tipos, dominios, acciones y eventos también reciben un nombre de archivo físico canónico dentro de la misma operación atómica.
- `app:docs` genera inventario técnico, fingerprint y diagramas Mermaid deterministas; `app:diagram` publica los grafos de dominios y contratos como artefacto independiente.
- `app:compat` compara dos proyectos sin ejecutar sus definiciones y falla ante rupturas de tipos, acciones, eventos, prefijos, dependencias, capacidades o garantías de auditoría/idempotencia.
- Cada proyecto nuevo incluye una guía operable y carga JAS mediante `JAS_ROOT` cuando aún no existe autoloader; una prueba end-to-end confirma creación, smoke test, documentación y compatibilidad en un proceso limpio.

### Alcance

- Generadores completos y actualización segura de definiciones.
- Formateador oficial.
- Analizador con resolución de namespaces y flujo entre dominios.
- Integración PHPStan.
- Language Intelligence Engine por CLI: diagnósticos, hover, definición, referencias y rename.
- Documentación y diagramas generados.
- Verificación de compatibilidad en CLI.

### Criterios de salida

- Proyecto nuevo llega a aplicación funcional siguiendo una sola guía.
- El motor de inteligencia opera sobre tipos, acciones, eventos y capacidades sin presentarse como LSP estándar.
- Renombrado conserva referencias y nombres físicos canónicos.
- CI rechaza contratos rotos y dependencias de dominio ilegales.

---

## Fase 8 — Escala y operación

Estado: **completada**

### Avance verificado

- Los trabajos que agotan intentos forman una DLQ durable sobre el mismo journal de la cola, conservando payload, contrato, error, intentos y deduplicación sin duplicar fuentes de verdad.
- La compactación conserva siempre la DLQ; listado y estadísticas tienen límites explícitos y los errores terminales se acotan antes de persistirse.
- El reproceso exige doble control ligado a la huella completa del trabajo fallido, valida al aprobador y crea un trabajo nuevo con referencias al original y a la aprobación.
- Repetir el reproceso devuelve el mismo trabajo; una aprobación consumida puede retomarse después de backpressure sin abrir una segunda autorización.
- CLI, auditoría, métricas y procedimiento operativo están documentados en `docs/JAS_OPERATIONS.md`; pruebas adversariales: `JAS DEAD LETTER: PASS`.
- `OperationalHealthEndpoint` separa liveness, readiness y diagnóstico autenticado: liveness no toca dependencias, readiness no revela componentes y el detalle falla cerrado antes de ejecutar checks.
- `public/health.php` comprueba PHP, escritura de runtime/DataCore y umbral de disco; usa texto nativo sin JSON, headers restrictivos, `503` con `Retry-After` y token operativo de al menos 32 bytes para detalles.
- Pruebas positivas y adversariales cubren dependencia caída, excepción sensible, autorización fallida, método y ruta inválidos; `JAS OPERATIONAL HEALTH: PASS`.
- `DiskPressureGuard` clasifica `normal`, `warning`, `critical` y `emergency`, emite alertas sólo en transiciones y considera el tamaño proyectado antes de admitir una escritura.
- DataCore, `StorageAgent`, la cola persistente y `JahLogger` aceptan la misma interfaz `WriteAdmission`; presión crítica bloquea trabajo nuevo y conserva reserva para cerrar leases y transiciones esenciales de cola hasta el umbral de emergencia.
- Un flush DataCore rechazado conserva el lote para reintento; pruebas inyectadas verifican warning temprano, deduplicación de alertas, bloqueo regular, reserva esencial, emergencia y recuperación sin llenar el disco real: `JAS DISK PRESSURE: PASS`.
- `RetentionScheduler` ejecuta tareas registradas bajo lock, respeta intervalo, ofrece dry-run y sólo confirma estado cuando todas terminan; un ciclo interrumpido se vuelve a intentar.
- `JahLogger` rota y retiene únicamente archivos propios después de validarlos; WAL conserva transacciones abiertas, outbox conserva publicaciones pendientes y la compactación de cola preserva la DLQ.
- Auditoría, eventos, evidencia criptográfica y replicación quedan explícitamente fuera del borrado automático; corrupción en log o WAL falla cerrada sin sustituir la fuente: `JAS RETENTION: PASS`.
- `JasbTelemetryExporter` publica métricas y trazas sólo mediante `TelemetryAdapter` externo y opcodes JASB compartidos con los SDK C/C++; el destino no accede a DataCore ni a internals.
- Métricas usan nombres y números acotados; trazas aplican allowlist estricta que excluye paths concretos, identidades, credenciales, headers y contexto arbitrario. Los lotes firmados se limitan a 1 MiB y la red exige cifrado SALK o TLS adicional.
- Pruebas verifican firma, opcodes, payload PHP nativo, redacción, rechazo de métricas inválidas, manipulación y falla contenida del adaptador: `JAS TELEMETRY EXPORT: PASS`.
- `QueueIsolationPolicy` separa capacidad activa y leases por partición de acción; una partición saturada se omite al arrendar y no consume la reserva de otros dominios.
- Colas grandes reservan por defecto 20 % de su capacidad frente a un único dominio; políticas explícitas permiten cuotas distintas para social, pagos u otros límites institucionales.
- La prueba satura `social` con prioridad alta y consumidor limitado mientras `payments` sigue aceptando, arrendando y completando trabajos; estadísticas reportan saturación por partición: `JAS SATURATION ISOLATION: PASS`.
- `OperationalPanelEndpoint` entrega un panel HTML de sólo lectura generado en PHP, exige `operations.view` antes de consultar fuentes y falla cerrado cuando identidad o telemetría no están disponibles.
- El panel limita y normaliza health, métricas, colas y particiones; excluye payloads, excepciones, secretos y rutas internas, escapa todo contenido y no contiene JavaScript ni JSON.
- CSP, `no-store`, `nosniff`, bloqueo de frames, respuesta 503 degradada y pruebas de autorización, inyección y contención quedan verificadas por `JAS OPERATIONAL PANEL: PASS`.
- `SustainedOperationEvidence` ofrece un burn-in opcional real de 604,800 segundos con manifiesto Ed25519, muestras encadenadas, detección de huecos/rollback y rechazo de campañas abreviadas; no se usa para fabricar siete días dentro del plazo del hackathon.
- `SustainedOperationProbe` ejerce cola aislada y DataCore reales, reabre ambos para recuperación, comprueba integridad de eventos/auditoría y registra disco/readiness con carga acotada.
- La calificación acelerada repite el probe, exige contabilidad exacta y cero fallos de integridad, recuperación, readiness o límites; sus resultados medidos se registran sin describirlos como siete días reales: `JAS OPERATIONS QUALIFICATION: PASS`.
- Evidencia local final: 500 ciclos, 10,500 operaciones aceptadas, cero pérdidas, integridad válida, 167.867285 segundos y 62.549 operaciones/segundo. Es evidencia reproducible del entorno de desarrollo, no una promesa universal ni siete días simulados.

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
- Calificación acelerada reproducible bajo carga repetida sin corrupción, pérdida ni crecimiento de cola; el burn-in real de siete días queda como validación posterior de producción y no como bloqueo del hackathon.

---

## Puerta 8.5 — LSP estándar externo

Estado: **en progreso; L0–L1 completadas**

Después de cerrar la Fase 8 se ejecutará íntegramente `JAS_LSP_PLAN.md`. El
editor hablará LSP/JSON-RPC únicamente con `jas-lsp-bridge` externo en C++; el
bridge hablará JASB con el motor semántico PHP. JAS y DataCore no recibirán JSON
ni incorporarán el bridge como dependencia del núcleo.

### Criterios de salida

- Lifecycle, documentos sin guardar, Unicode, diagnósticos, hover, definición, referencias y rename operan desde editores estándar.
- Rename devuelve `WorkspaceEdit` negociado y no modifica archivos desde el servidor.
- Bridge, motor PHP y framing JASB pasan pruebas de protocolo, integración, editores, fuzzing y seguridad.
- Existe un binario externo firmado con instrucciones reproducibles y sin dependencia instalada para el usuario.

### Avance verificado

- L0 completada: opcodes 600–691, mensajes tipados request/notificación/respuesta/error y payload TLV canónico JASL sin JSON.
- PHP valida campos exactos, IDs, Unicode, límites y firma JASB; C/C++ comparte opcodes y valida estructura/profundidad sin asignar memoria.
- Round-trip PHP → JASB/JASL → C validado con opcode 600; amenazas, procesos y pendientes honestos documentados en `docs/JAS_LSP_SECURITY.md`.
- Evidencia reproducible: `php tests/test_jas_language_protocol.php` y compilación C con `-Wall -Wextra -Werror` en PASS.
- L1 completada: `DocumentStore` confina URI al workspace, impone versiones monotónicas y límites globales, conserva documentos nuevos/no guardados como fuente autoritativa y los elimina al cerrar sesión.
- `LanguagePositionCodec` convierte posiciones UTF-8/UTF-16/UTF-32, rechaza surrogate split, offsets multibyte y posiciones dentro de CRLF; el índice usa overlays sin escribir ni ejecutar fuentes.
- Pruebas demuestran hover/definición y diagnóstico sobre cambios no guardados, archivo nuevo virtual, rollback de versión y symlink externo: `JAS LANGUAGE DOCUMENTS AND UNICODE: PASS`.

No se iniciará la Fase 9 hasta cerrar esta puerta y registrar evidencia.

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

Este registro se sincronizó con las secciones normativas el 2026-07-16. Cada
cambio futuro de estado debe actualizar simultáneamente la fase y esta tabla.

| Fase | Estado | Evidencia reproducible |
|---|---|---|
| 1 | Completada | Núcleo y definiciones: `php tests/test_jas_core.php` y `php tests/test_jas_definition.php` |
| 2 | Completada | Transacciones y recuperación: `php tests/test_datacore_database.php` y `php tests/test_jas_regressions.php` |
| 3 | Completada | DataCore empresarial y SQL Mirror: `php tests/test_datacore_database.php` y `php tests/benchmark.php` |
| 4 | Completada | Backup, restauración y continuidad: `php tests/test_datacore_backup.php` |
| 5 | Completada | Identidad y acceso institucional: `php tests/test_jas_identity.php` y `php tests/test_jas_security.php` |
| 6 | Completada | JAS Web: `php tests/test_jas_web.php`, `php tests/test_jas_accessibility.php` y `php tests/test_jas_upload.php` |
| 7 | Completada | Tooling y ciclo de proyecto: `php tests/test_jas_tooling.php`, `php tests/test_jas_language_engine.php`, `php tests/test_jas_project_lifecycle.php` y `php bin/jas static` |
| 8 | Completada | Operación segura y calificación acelerada: `php tests/test_jas_operations_qualification.php 500`; 10,500/10,500 operaciones, integridad PASS; gate transversal: `php tests/run_all.php` |
| 8.5 | En progreso | L0–L1: `php tests/test_jas_language_protocol.php` y `php tests/test_jas_language_documents.php`; siguiente L2 servicio binario PHP |
| 9 | Pendiente | No iniciada |
| 10 | Pendiente | No iniciada |

La comprobación transversal vigente es `php tests/run_all.php`, cuyo resultado
registrado es `JAS SUITE: PASS`.

## Próxima acción obligatoria

Continuar **Puerta 8.5 — LSP estándar externo** con L2: servicio binario PHP
por stdio, lifecycle y despacho semántico. No iniciar la Fase 9 antes de cerrar el plan.

## Resumen de trabajo restante

- Fases 1–8: completadas.
- Puerta 8.5: L0–L1 completadas; L2–L7 pendientes según `JAS_LSP_PLAN.md`.
- Fase 9: pendiente completa; incluye fallos, red, rotación bajo carga, threat
  model y revisiones externas. La revisión criptográfica y el penetration test
  requieren especialistas independientes y no pueden autodeclararse.
- Fase 10: pendiente completa; aplicación gubernamental/red social de referencia,
  pruebas integrales, congelación de API 2.0 y guía de migración.
