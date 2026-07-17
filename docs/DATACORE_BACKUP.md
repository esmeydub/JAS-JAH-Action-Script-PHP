# Backup y continuidad de DataCore

Estado: **Fase 4 en progreso**.

`DataCoreBackupService` crea archivos `.jahb` nativos, sin JSON. Cada archivo incluido se cifra con propósito y ruta propios; el manifiesto completo se firma. La publicación usa archivo temporal, `flush`, `fsync` cuando está disponible y `rename` atómico.

## Consistencia

Todos los `DataCoreTurbo` y `DataCoreTransactionManager` de una instalación deben compartir el mismo `DataCoreContinuityLock`. Los escritores toman modo compartido y el backup toma modo exclusivo. El servicio ejecuta los `flushers` registrados dentro de esa ventana antes de enumerar datos, índices, journals y metadata de claves.

El directorio de backups debe estar fuera de la raíz capturada. Symlinks y rutas relativas peligrosas se rechazan.

## Restauración

- El archivo completo se verifica y descifra antes de publicar.
- Cada entrada valida ruta, longitud y SHA-256.
- El destino debe estar vacío.
- La restauración usa staging y sólo después mueve la jerarquía verificada.
- `restorePointInTime()` selecciona el snapshot íntegro más reciente que no exceda el instante solicitado.

La granularidad point-in-time actual es la frecuencia de snapshots. Aún no existe replay temporal de cada WAL; no debe anunciarse una precisión menor al intervalo entre backups.

## Retención

`prune()` conserva siempre un mínimo configurable de snapshots recientes y sólo elimina backups válidos que exceden la edad configurada. Opera en `dry-run` por defecto. Un archivo corrupto nunca se borra automáticamente: queda para investigación.

## Prueba reproducible

```bash
php tests/test_datacore_backup.php
```

La prueba confirma cifrado/firma, detección de alteración, restauración en vacío, lectura posterior con `DataCoreTurbo`, selección point-in-time, rechazo de destino ocupado y prohibición de backups dentro de la fuente.

## Medición reproducible

```bash
php benchmarks/datacore_backup.php 5000
```

El comando publica tamaño fuente/archivo y segundos de creación, verificación y restauración. Siempre valida una lectura DataCore restaurada; una medición sin corrección no se considera válida.

Línea base local del 16 de julio de 2026: PHP 8.4.22, Linux, 5,000 registros,
4,202,602 bytes de fuente y 8,048,247 bytes cifrados; creación 0.068148 s,
verificación 0.031700 s y restauración 0.109296 s. Resultado de lectura: PASS.
Es una microprueba local, no un RTO/RPO contractual.
