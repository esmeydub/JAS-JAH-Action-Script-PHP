# Estado de las API de JAS 2.0

Estado: **congelada desde 2026-07-18**

## Contrato de estabilidad

La superficie estable de JAS 2.0 conserva compatibilidad durante toda la versión
mayor. Las adiciones compatibles pueden publicarse en versiones menores; una
eliminación, cambio de contrato o comportamiento incompatible exige JAS 3.0,
reporte de `app:compat` y guía de migración. Los diagnósticos públicos conservan
su código estable aunque el texto explicativo pueda mejorar.

La API oficial del motor es PHP puro. JavaScript, Node y otros runtimes no forman
parte del núcleo. Un lenguaje externo sólo puede usar un adaptador fuera de
proceso y JASB, como los SDK C/C++, sin acceso directo a DataCore ni a internals.

## API pública estable 2.0

- `Jah\JAS\Definition`: aplicaciones, dominios, acciones, eventos y compatibilidad.
- `Jah\JAS\Type\TypeRegistry`: tipos, alias y validación de contratos.
- `Jah\JAS\Action\ActionScript`: acciones locales tipadas.
- `Jah\JAS\Runtime`: ejecución gobernada y procesamiento de eventos.
- `Jah\DataCore`: persistencia, consultas indexadas, transacciones, migraciones,
  cifrado, retención, SQL Mirror gobernado, backup y restauración.
- `Jah\JAS\Security`: capacidades, identidad institucional, claves, replay y
  doble control. Los adaptadores de federación/WebAuthn siguen siendo fronteras.
- `Jah\JAS\Persistence`: WAL, outbox, auditoría, idempotencia y journals.
- `Jah\JAS\Queue`: trabajos, leases, backpressure y colas persistentes.
- `Jah\JAS\Web`: petición, respuesta, router, middleware, HTML seguro, formularios,
  componentes, i18n, accesibilidad, uploads y streaming.
- `Jah\JAS\Protocol` y `Jah\JAS\Transport`: JASB, JASL y sobres SALK.
- `Jah\JAS\Diagnostics`: códigos, diagnósticos, redacción y límites de error.
- `Jah\JAS\Tooling`: lector literal, generadores, analizador, formateador,
  documentación, diagramas y ciclo de compatibilidad usados por `bin/jas`.
- Comandos estables de `bin/jas`: `health`, `make:project`, `make:domain`,
  `make:type`, `make:action`, `make:event`, `type:add-field`,
  `domain:add-dependency`, `action:configure`, `format`, `analyze`, `app:docs`,
  `app:diagram`, `app:compat`,
  `diagnose`, `core:seal` y `core:verify`.

La aplicación de referencia en `examples/reference_portal` usa únicamente esta
superficie estable. La guía de actualización está en `docs/JAS_2_0_MIGRATION.md`.

## Experimentales

- `Jah\JAS\Cluster`, `Consensus`, `Replication`, `Sharding`, `Snapshot`, `Sync`
  y `Balance`.
- `Jah\JAS\ObjectGraph` y `Jah\JAS\Dispatch`.
- `Jah\JAS\Observability` y adaptadores externos de telemetría JASB.
- El servicio semántico binario y el bridge LSP C++ fuera de proceso.
- SDK C/C++ y adaptadores WebAuthn, OIDC, SAML y LDAP.

Una API experimental puede cambiar en una versión menor, siempre con nota de
migración. No se incluye en la promesa de compatibilidad estable 2.0.

## Heredadas y aisladas

- `php_actionscript_php_doc/` contiene prototipos históricos fuera del autoload.
- `AuthStore` se conserva sólo como proveedor de compatibilidad; los sistemas
  nuevos usan `InstitutionalIdentityService` y DataCore cifrado.
- Las clases bajo `app/memory`, `app/cache`, `app/http` y `app/security` son de la
  aplicación histórica de demostración, no API pública del framework.

## Reglas de dependencia

1. Código nuevo importa únicamente la API estable necesaria de `Jah\JAS\*` y
   `Jah\DataCore\*`.
2. Ninguna API estable carga prototipos históricos.
3. El núcleo no admite JSON, JavaScript, Node, Composer ni ejecución de fuentes
   analizadas como configuración.
4. Toda ruptura futura requiere versión mayor, evidencia de compatibilidad y
   guía de migración.
