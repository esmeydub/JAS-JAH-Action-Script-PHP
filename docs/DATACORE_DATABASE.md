# DataCore Database

> Nota de consulta: `query()` rechaza un recorrido completo por defecto. Use índices
> con `findByIndex()`/`findByRange()` para rutas críticas o declare conscientemente una
> tarea administrativa mediante `scan()` con límite obligatorio.

DataCore es la base de datos nativa de JAS. `DataCoreDatabase` añade contratos,
control optimista y cifrado sobre el almacenamiento segmentado append-only de
`DataCoreTurbo`.

```php
$database = (new DataCoreDatabase($storage, $types, $runtimePath, $masterKey))
    ->collection('expedientes', 'Expediente')
    ->encryptFields('expedientes', ['curp', 'nombre', 'domicilio']);
```

## Garantías

- La clave maestra debe tener al menos 256 bits y no debe guardarse junto a los datos.
- Los campos seleccionados usan XSalsa20-Poly1305 mediante Sodium `secretbox`.
- El documento completo incluye HMAC-SHA512 para detectar alteraciones.
- Cada actualización exige una versión esperada y rechaza escrituras obsoletas.
- Los bloqueos por documento serializan modificaciones concurrentes.
- Las migraciones tienen versión y checksum; una migración aplicada no puede cambiar.

Estas garantías son controles técnicos, no una certificación gubernamental. Una
implantación regulada requiere gestión institucional de claves, respaldo probado,
retención, segregación de funciones, auditoría externa y cumplimiento de la
normativa aplicable al organismo y país.

## Compactación

```bash
bin/jas datacore:compact expedientes
bin/jas datacore:compact expedientes --apply
```

El primer comando sólo genera un reporte. Al aplicar, DataCore bloquea escrituras,
conserva todas las versiones bajo `_legal_hold`, escribe staging, compara hashes de
cada documento, mueve los segmentos anteriores a backup, publica los nuevos y
reconstruye índices. `--drop-backup` elimina el rollback sólo después de verificar y
publicar; debe usarse cuando exista un snapshot externo comprobado.
