# Estado de las API de JAS 2.0

## Límite del núcleo

La API oficial del motor es PHP puro. Tipos, rutinas, definiciones y persistencia
se implementan dentro de PHP. JavaScript, Node y otros runtimes no forman parte
del núcleo. Cualquier lenguaje externo usa un adaptador fuera de proceso y JASB,
como los SDK C/C++, sin acceso directo a DataCore ni a internals del runtime.

Este inventario es normativo durante la consolidación. Una API estable conserva
compatibilidad dentro de la versión mayor; una experimental puede cambiar con
nota de migración; una heredada no puede usarse en código nuevo.

## Estables

- `Jah\JAS\Definition`: definición tipada de aplicaciones, dominios, acciones y eventos.
- `Jah\JAS\Type\TypeRegistry`: motor único de tipos y validación.
- `Jah\JAS\Action\ActionScript`: motor único de acciones locales.
- `Jah\JAS\Runtime`: ejecución gobernada, eventos y runtime binario.
- `Jah\DataCore`: persistencia nativa, serialización PHP segura, transacciones y compactación.
- `Jah\JAS\Security`: roles, capacidades, autenticación, replay, claves y doble control.
- `Jah\JAS\Persistence`: WAL, outbox, auditoría, idempotencia y estado.
- `Jah\JAS\Queue`: trabajos persistentes y servicio de colas.
- `Jah\JAS\Web`: petición, respuesta, rutas, middleware, HTML seguro y formularios.
- `Jah\JAS\Protocol` y `Jah\JAS\Transport`: protocolo binario y sobres cifrados SALK.

## Experimentales

- `Jah\JAS\Cluster`, `Consensus`, `Replication`, `Sharding`, `Snapshot`, `Sync` y `Balance`.
- `Jah\JAS\ObjectGraph` y `Jah\JAS\Dispatch`.
- `Jah\JAS\Tooling` y la interfaz `bin/jas`.

Estas API están probadas, pero no tendrán estabilidad contractual hasta completar
las fases de continuidad, carga distribuida y experiencia de desarrollo.

## Heredadas y aisladas

- `php_actionscript_php_doc/`: prototipos históricos, ejemplos y compiladores experimentales.
  No es parte del autoload ni del runtime oficial.
- `JasContextRuntime` sustituyó completamente la identidad histórica del módulo de memoria.
- Las clases bajo `app/memory`, `app/cache`, `app/http` y `app/security` pertenecen a
  la aplicación de demostración, no a la API pública estable del framework.

## Reglas de dependencia

1. El código nuevo importa únicamente `Jah\JAS\*` y `Jah\DataCore\*`.
2. Ninguna API estable carga archivos desde `php_actionscript_php_doc`.
3. No se admiten formatos JSON en almacenamiento, protocolo o configuración operativa.
4. Toda ruptura futura requiere reporte de compatibilidad y guía de migración.
