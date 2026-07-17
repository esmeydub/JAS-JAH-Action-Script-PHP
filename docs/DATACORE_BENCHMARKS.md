# Benchmarks reproducibles de DataCore

Este documento publica mediciones, no promesas universales. DataCore es la fuente de verdad de JAS por sus contratos, cifrado, integridad y gobierno; su rendimiento debe demostrarse por carga de trabajo y versión.

## Cómo reproducir

```bash
php benchmarks/datacore_sql.php 2000
```

La prueba crea 2,000 documentos equivalentes y ejecuta 1,000 consultas exactas sobre un índice compuesto. Verifica que ambos motores devuelvan el mismo identificador. No usa JSON ni red y elimina sus archivos temporales al terminar.

Condiciones de durabilidad: DataCore confirma y vacía cada documento; SQLite usa WAL, `synchronous=FULL` y agrupa las 2,000 escrituras en una transacción. Por ello esta medición representa las APIs actuales, pero no una igualdad de estrategia de lote.

## Línea base local — 16 de julio de 2026

Entorno: PHP 8.4.22, Linux, almacenamiento local, proceso único.

| Motor | Escritura | 1,000 lecturas | CPU proceso | RAM incremental | Pico incremental | Disco |
|---|---:|---:|---:|---:|---:|---:|
| DataCore | 0.926700 s | 280.437432 ms | 1.200917 s | 2.00 MiB | 2.00 MiB | 4.66 MiB |
| SQLite | 0.008646 s | 3.243097 ms | 0.006756 s | 0 B observado | 0 B observado | 361.93 KiB |

Resultado de corrección: **PASS**.

En esta microprueba SQLite fue más rápido y usó menos disco. JAS no debe anunciar lo contrario con estos datos. La comparación también hizo visible una lectura repetida del journal de índices; la caché segura añadida redujo las 1,000 lecturas DataCore desde aproximadamente 1,623 ms hasta aproximadamente 280 ms en el mismo entorno.

## Límites de interpretación

- Una microprueba caliente no representa una red social, concurrencia, replicación, cifrado por sujeto ni recuperación tras caída.
- La RAM de PDO/SQLite puede residir fuera del incremento que reporta `memory_get_usage`; cero significa “no observado por PHP”, no consumo real nulo.
- La CPU es tiempo de usuario más sistema del proceso PHP y no incluye métricas del kernel o dispositivos.
- Los resultados cambian por CPU, filesystem, configuración, versión, tamaño de documento y garantías de durabilidad.
- Antes de una afirmación comercial deben ejecutarse cargas institucionales representativas y publicar ambiente, parámetros y resultados completos.

## Próximas mediciones obligatorias

- lotes DataCore con una sola confirmación durable;
- concurrencia multiproceso y conflictos únicos;
- recuperación después de interrupción;
- consultas por rango y compactación;
- documentos cifrados y destrucción por sujeto;
- tamaños de 100 mil, un millón y cargas sostenidas, con límites de hardware explícitos.
