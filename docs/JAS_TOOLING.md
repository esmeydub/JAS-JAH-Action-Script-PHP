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
composer install
php bin/jas static
composer verify
```

`phpstan.neon.dist` analiza todo `src/JAS` y `src/DataCore` en nivel 5, sin
baseline ni exclusiones. `treatPhpDocTypesAsCertain` está desactivado porque las
APIs públicas vuelven a comprobar valores recibidos aunque exista documentación
de tipos; los tipos nativos siguen siendo estrictos. La versión de desarrollo
parte de PHPStan 2.1.56 y acepta actualizaciones compatibles de la rama 2.x.

El análisis se ejecuta en modo determinista de un solo proceso. Así también puede
operar en contenedores y entornos gubernamentales restringidos que impiden abrir
puertos locales durante la verificación.

El workflow ejecuta PHPStan y la suite en PHP 8.2 y 8.4. Las acciones externas
están fijadas por SHA completo, los permisos del token se reducen a lectura y
las dependencias se instalan desde `composer.lock`. Un error estático, un
contrato roto o una prueba fallida bloquea CI; no se genera baseline automática.

## Servicio de lenguaje JAS

El motor de lenguaje indexa directamente las definiciones PHP literales; no las
ejecuta, no crea índices persistentes y no utiliza JSON. Reconoce tipos, dominios,
acciones, eventos y capacidades. Todas las posiciones del CLI usan línea y
columna comenzando en 1.

```bash
php bin/jas lsp:diagnostics mi-proyecto
php bin/jas lsp:hover mi-proyecto app/Actions/Crear.php 8 25
php bin/jas lsp:definition mi-proyecto app/Actions/Crear.php 8 25
php bin/jas lsp:references mi-proyecto app/Actions/Crear.php 8 25
php bin/jas lsp:rename mi-proyecto app/Actions/Crear.php 8 25 Solicitud
php bin/jas lsp:rename mi-proyecto app/Actions/Crear.php 8 25 Solicitud --apply
```

`lsp:rename` sólo muestra el plan de cambios de forma predeterminada. Con
`--apply`, valida el nuevo identificador según su clase, rechaza colisiones y
rutas fuera del proyecto, comprueba el hash de cada archivo bajo bloqueo y
prepara todas las definiciones antes de reemplazarlas. Si una publicación falla,
restaura las copias verificadas. El renombrado conserva las referencias entre
entradas, salidas, payloads, dominios y capacidades.
