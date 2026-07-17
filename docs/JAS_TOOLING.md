# Herramientas JAS

```bash
bin/jas make:project apps/portal "Portal Gubernamental"
bin/jas make:domain apps/portal Tramites tramite
bin/jas make:type apps/portal NuevoTramite
bin/jas make:event apps/portal Tramites tramite.creado NuevoTramite
bin/jas make:action apps/portal Tramites tramite.crear NuevoTramite NuevoTramite tramites.create
```

El generador crea carpetas separadas para dominios, tipos, acciones, web,
configuración, pruebas y runtime. Nunca sobrescribe archivos existentes. La
aplicación generada usa definiciones PHP y establece `JAS_ROOT` para localizar el
runtime durante desarrollo o despliegue.

`GeneratedApplicationLoader` incorpora los archivos por orden estable: tipos,
dominios, eventos y acciones. `PhpDefinitionReader` sólo acepta arrays PHP
literales con strings, enteros, booleanos, nulos y arrays anidados. Nunca usa
`require` ni `eval` sobre una definición: llamadas, variables, clases y código
adicional fallan cerrados sin ejecutarse. Las claves inesperadas, formas
ambiguas y referencias inválidas también se rechazan antes de crear el runtime.
No existe manifiesto JSON ni edición textual frágil de `application.php`:
agregar una definición es una creación exclusiva y el cargador vuelve a validar
el grafo completo.

## Actualización y formato seguros

```bash
bin/jas type:add-field apps/portal NuevoTramite descripcion? string
bin/jas domain:add-dependency apps/portal Tramites Identidad
bin/jas action:configure apps/portal tramite.crear NuevoTramite TramiteCreado tramites.create
bin/jas format apps/portal
bin/jas format apps/portal --check
```

`DefinitionEditor` sólo localiza nombres validados dentro de las carpetas de
definición, rechaza enlaces simbólicos y comprueba referencias antes de editar.
`PhpDefinitionStore` vuelve a leer bajo un bloqueo exclusivo, escribe un archivo
temporal en el mismo directorio, fuerza el contenido a disco, lo relee con el
parser seguro y finalmente hace un reemplazo atómico. Un fallo deja intacta la
definición anterior.

El formateador oficial normaliza exclusivamente definiciones JAS; nunca intenta
reescribir controladores o PHP arbitrario. Primero analiza todos los archivos y
después aplica cambios. `--check` no escribe y devuelve código distinto de cero
cuando CI encuentra una definición fuera del formato canónico.

## Análisis

```bash
bin/jas analyze apps/portal
```

El analizador reporta archivo, línea y código. Comprueba estructura obligatoria,
`strict_types`, funciones de ejecución peligrosas, formatos prohibidos, posibles
secretos y uso de superglobales fuera del límite web. Este análisis complementa,
pero no sustituye, PHPStan, revisión humana y pruebas de seguridad externas.

El índice semántico aplica PSR-4 interno (`App\\` → `app/`), detecta símbolos
duplicados, imports internos sin definición y clases colocadas en una ruta que
no corresponde a su namespace. El código de un dominio se organiza en
`app/Domains/<Dominio>/` y usa `App\\Domains\\<Dominio>`; importar otro dominio
exige que su definición aparezca en `dependencies`.

Además, `analyze` reconstruye y valida el grafo completo con el lector literal
seguro. Un tipo ausente, una acción fuera del prefijo de su dominio, un evento
inválido o un ciclo de dependencias hace fallar CI sin ejecutar las definiciones.
Los diagnósticos semánticos estables son `JAS030`–`JAS050`.

## PHPStan y CI

```bash
JAS_PHPSTAN=/ruta/verificada/phpstan.phar php bin/jas static
php tests/run_all.php
```

`phpstan.neon.dist` analiza todo `src/JAS` y `src/DataCore` en nivel 5, sin
baseline ni exclusiones. `treatPhpDocTypesAsCertain` está desactivado porque las
APIs públicas vuelven a comprobar valores recibidos aunque exista documentación
de tipos; los tipos nativos siguen siendo estrictos. CI descarga PHPStan 2.1.56
como PHAR, verifica su SHA-256 fijado y no lo incorpora como dependencia.

El análisis se ejecuta en modo determinista de un solo proceso. Así también puede
operar en contenedores y entornos gubernamentales restringidos que impiden abrir
puertos locales durante la verificación.

El workflow ejecuta PHPStan y la suite en PHP 8.2 y 8.4. Las acciones externas
están fijadas por SHA completo y los permisos del token se reducen a lectura.
Un error estático, un contrato roto o una prueba fallida bloquea CI; no se genera
baseline automática ni se usa Composer, Node, npm o JavaScript.

## JAS Language Intelligence Engine

El motor de inteligencia indexa directamente las definiciones PHP literales; no
las ejecuta, no crea índices persistentes y no utiliza JSON. Reconoce tipos,
dominios, acciones, eventos y capacidades. Todas las posiciones del CLI usan
línea y columna comenzando en 1.

El motor PHP también ofrece un servicio persistente JASB con documentos abiertos,
posiciones Unicode, lifecycle, diagnósticos y navegación. El adaptador externo
`sdk/cpp/lsp/jas_lsp_bridge.cpp` ya completa el lifecycle LSP/JSON-RPC estándar
por stdio y traduce la allowlist inicial. JSON sólo existe en ese proceso C++:
el motor PHP y DataCore reciben exclusivamente JASB/JASL firmado. La Puerta 8.5
está cerrada con seguridad L6 y distribución/interoperabilidad L7 verificadas.
El paquete y sus límites están documentados en `docs/JAS_LSP_DISTRIBUTION.md`.

```bash
php bin/jas language:diagnostics mi-proyecto
php bin/jas language:hover mi-proyecto app/Actions/Crear.php 8 25
php bin/jas language:definition mi-proyecto app/Actions/Crear.php 8 25
php bin/jas language:references mi-proyecto app/Actions/Crear.php 8 25
php bin/jas language:rename mi-proyecto app/Actions/Crear.php 8 25 Solicitud
php bin/jas language:rename mi-proyecto app/Actions/Crear.php 8 25 Solicitud --apply
make -C sdk/cpp/lsp test
sdk/cpp/lsp/jas-lsp-bridge "$(command -v php)" "$PWD/bin/jas" "$PWD/mi-proyecto"
tests/test_jas_lsp_distribution.sh
```

`language:rename` sólo muestra el plan de cambios de forma predeterminada. Con
`--apply`, valida el nuevo identificador según su clase, rechaza colisiones y
rutas fuera del proyecto, comprueba el hash de cada archivo bajo bloqueo y
prepara todas las definiciones antes de reemplazarlas. Si una publicación falla,
restaura las copias verificadas. El renombrado conserva las referencias entre
entradas, salidas, payloads, dominios y capacidades. Para tipos, dominios,
acciones y eventos también renombra físicamente el archivo de declaración con el
nombre canónico; la vista previa expone tanto los cambios de contenido como el
movimiento previsto.

## Ciclo completo del proyecto

La guía canónica está en [`JAS_GETTING_STARTED.md`](JAS_GETTING_STARTED.md) y
también se integra en el `README.md` de cada proyecto nuevo. Un proyecto generado
puede ejecutar su prueba de humo en un proceso limpio usando únicamente
`JAS_ROOT` para localizar el runtime.

```bash
php bin/jas app:docs proyecto JAS_APPLICATION.md
php bin/jas app:diagram proyecto JAS_APPLICATION.mmd
php bin/jas app:compat version-desplegada version-candidata
```

La documentación incorpora inventario, fingerprint y diagramas Mermaid de
dominios y contratos. `app:compat` falla ante eliminaciones y cambios de tipos,
prefijos, dependencias, capacidades, auditoría, idempotencia o eventos; las
adiciones compatibles se reportan como advertencias.
