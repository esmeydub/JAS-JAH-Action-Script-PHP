# Portal Institucional de Referencia JAS

Aplicación web funcional para demostrar una arquitectura empresarial o
gubernamental organizada con JAS 2.0. DataCore es la fuente de verdad y no hay
dependencias de JavaScript, Node, Composer ni JSON.

## Alcance implementado

El portal separa ocho dominios: identidad, usuarios, publicaciones, feeds,
mensajería, moderación, notificaciones y auditoría. Toda operación cruza el flujo
`entrada → contrato → capacidad → acción → DataCore → auditoría → salida`.
Feeds, moderación y notificaciones poseen colas persistentes independientes, por
lo que pueden asignarse a workers y presupuestos operativos distintos.

Los datos de identidad, mensajes y publicaciones sensibles se cifran antes de
persistirse. Las referencias, índices y contratos se validan en DataCore. Los
roles disponibles son `admin`, `citizen`, `moderator` y `auditor`; una acción no
puede ampliar sus propios privilegios.

## Instalación

Requiere PHP 8.2+, Sodium y una copia verificada de JAS. Los secretos nunca se
reciben por argumentos ni se guardan en el repositorio.

```bash
export JAS_ROOT=/ruta/segura/JAS-JAH-Action-Script-PHP
export PORTAL_MASTER_KEY="$(openssl rand -base64 48)"
export PORTAL_IDENTITY_PEPPER="$(openssl rand -hex 48)"
export PORTAL_ADMIN_PASSWORD='reemplace-por-un-secreto-largo'
php bin/install.php
unset PORTAL_ADMIN_PASSWORD
```

En producción use un gestor de secretos, TLS en el proxy, usuario de sistema sin
privilegios y permisos `0700` para `runtime`. No reutilice las claves del ejemplo.

## Desarrollo y verificación

```bash
php "$JAS_ROOT/bin/jas" format . --check
php "$JAS_ROOT/bin/jas" analyze .
php tests/smoke.php
php "$JAS_ROOT/tests/test_jas_reference_portal.php"
JAS_ROOT="$JAS_ROOT" php -S 127.0.0.1:8080 -t public
```

El transporte HTTP usa formularios y respuestas `text/plain`; no usa JSON. Para
iniciar sesión envíe por `POST /login` los campos `id`, `username`, `password`,
`device_id` y `device_label`. Las demás rutas requieren `Authorization: Bearer
<token>`:

- `GET /feed`: `id`, `limit`.
- `POST /publicaciones`: `id`, `content`.
- `POST /mensajes`: `id`, `recipient_id`, `body`.
- `POST /moderacion`: `id`, `decision` (`approved` o `rejected`).
- `GET /notificaciones`: `id`, `limit`.
- `GET /auditoria`: `id`.

## Operación

Supervise `GET /health`, espacio disponible, permisos del directorio, latencia,
errores de autorización, integridad del journal y profundidad de cada carpeta en
`runtime/queues`. Separe workers por cola; no permita que una saturación de feed
consuma la capacidad reservada a moderación o notificaciones. Rote claves con el
procedimiento probado de JAS y mantenga copias cifradas fuera del host.

Antes de actualizar, ejecute `jas app:compat` contra una copia de la versión
desplegada, haga backup verificado, pruebe restauración aislada y aplique la
actualización canaria. Una incompatibilidad cancela el despliegue.

## Recuperación de desastre

1. Aísle el nodo afectado y conserve logs y journals para investigación.
2. Verifique firma e integridad del backup fuera del host comprometido.
3. Restaure en un directorio vacío con las claves provenientes del gestor seguro.
4. Ejecute `jas analyze`, la prueba funcional y `auditoria.verify`.
5. Rote credenciales y claves si existe sospecha de exposición.
6. Promueva el nodo restaurado sólo después de reconciliar colas e integraciones.

La prueba de Fase 10 crea, verifica y restaura un backup cifrado y vuelve a leer
el feed usando una sesión y roles restaurados. Esto es evidencia técnica local,
no una certificación externa ni reemplaza un simulacro operacional independiente.
