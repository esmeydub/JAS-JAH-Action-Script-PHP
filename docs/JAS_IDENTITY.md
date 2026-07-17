# Identidad de JAS

JAS significa **Jah ActionScript**. Es un runtime PHP de acciones, eventos,
objetos activos, grafos y estado persistente.

## Lo que pertenece al núcleo

- Grafos de acciones y scheduler basado en Fibers.
- Objetos activos que reaccionan a eventos.
- Protocolo binario JASB y seguridad SALK.
- WAL, cola duradera, workers, DataCore y memoria por niveles.
- Clúster, replicación, quorum, fencing y snapshots.

## Límites deliberados

JAS no incluye conectores de inteligencia artificial, proveedores cloud ni un
modelo generativo. El contexto y la memoria son datos para acciones JAS; no
implican una conversación con un servicio externo.

El runtime no usa JSON como formato interno. La persistencia utiliza formatos
nativos JAH/PHP y el transporte entre runtimes utiliza JASB binario.

`MemoryActionScript` se conserva únicamente como nombre de una clase compatible
del prototipo inicial. Su entrada local es `executeContext()`.
