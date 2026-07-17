# Herramientas JAS

```bash
bin/jas make:project apps/portal "Portal Gubernamental"
bin/jas make:domain apps/portal Tramites tramite
bin/jas make:type apps/portal NuevoTramite
bin/jas make:action apps/portal Tramites tramite.crear
```

El generador crea carpetas separadas para dominios, tipos, acciones, web,
configuración, pruebas y runtime. Nunca sobrescribe archivos existentes. La
aplicación generada usa definiciones PHP y establece `JAS_ROOT` para localizar el
runtime durante desarrollo o despliegue.

## Análisis

```bash
bin/jas analyze apps/portal
```

El analizador reporta archivo, línea y código. Comprueba estructura obligatoria,
`strict_types`, funciones de ejecución peligrosas, formatos prohibidos, posibles
secretos y uso de superglobales fuera del límite web. Este análisis complementa,
pero no sustituye, PHPStan, revisión humana y pruebas de seguridad externas.
