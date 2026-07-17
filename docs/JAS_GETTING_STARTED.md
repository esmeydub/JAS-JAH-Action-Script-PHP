# Crear una aplicación JAS funcional

Esta es la ruta canónica y única para iniciar un proyecto con JAS — JAH Action
Script PHP. El generador coloca una copia adaptada de esta guía en el `README.md`
de cada aplicación nueva.

## 1. Crear el proyecto

```bash
export JAS_ROOT=/ruta/segura/JAS-JAH-Action-Script-PHP
php "$JAS_ROOT/bin/jas" make:project portal "Portal Institucional"
cd portal
```

## 2. Declarar el primer contrato

```bash
php "$JAS_ROOT/bin/jas" make:domain . Tramites tramite
php "$JAS_ROOT/bin/jas" make:type . Solicitud
php "$JAS_ROOT/bin/jas" make:action . Tramites tramite.crear Solicitud Solicitud tramites.create
php "$JAS_ROOT/bin/jas" make:event . Tramites tramite.creado Solicitud 1
```

Las definiciones son PHP literal estricto. JAS no las ejecuta durante análisis,
documentación, navegación o compatibilidad y no necesita manifiestos JSON.

## 3. Verificar antes de ejecutar

```bash
php "$JAS_ROOT/bin/jas" format . --check
php "$JAS_ROOT/bin/jas" analyze .
php tests/smoke.php
```

`analyze` rechaza contratos incompletos, símbolos internos ausentes, dependencias
entre dominios no declaradas, secretos aparentes y construcciones prohibidas.

## 4. Generar evidencia técnica

```bash
php "$JAS_ROOT/bin/jas" app:docs . JAS_APPLICATION.md
php "$JAS_ROOT/bin/jas" app:diagram . JAS_APPLICATION.mmd
```

Ambos artefactos son deterministas respecto del manifiesto validado. La
documentación incluye fingerprint, inventario tipado y diagramas Mermaid de
dominios y contratos.

## 5. Comprobar una actualización

Conserva una copia de la versión desplegada y compara ambos proyectos:

```bash
php "$JAS_ROOT/bin/jas" app:compat ../portal-desplegado .
```

El comando termina con código distinto de cero ante eliminaciones o cambios de
contrato, tipo, prefijo, dependencia, auditoría, idempotencia o evento. Las
adiciones compatibles se publican como advertencias revisables.

## 6. Ejecutar localmente

```bash
JAS_ROOT="$JAS_ROOT" php -S 127.0.0.1:8080 -t public
```

El servidor integrado es sólo para desarrollo. Un despliegue institucional debe
usar TLS, secretos externos al repositorio, permisos mínimos y los controles de
operación definidos en las fases posteriores del Plan Maestro.
