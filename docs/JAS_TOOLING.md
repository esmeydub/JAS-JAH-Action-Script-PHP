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

## Análisis

```bash
bin/jas analyze apps/portal
```

El analizador reporta archivo, línea y código. Comprueba estructura obligatoria,
`strict_types`, funciones de ejecución peligrosas, formatos prohibidos, posibles
secretos y uso de superglobales fuera del límite web. Este análisis complementa,
pero no sustituye, PHPStan, revisión humana y pruebas de seguridad externas.
