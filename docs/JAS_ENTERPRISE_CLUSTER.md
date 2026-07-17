# JAS Enterprise Cluster v1.3

Esta versión añade la capa operativa para ejecutar JAS en varios nodos sin convertir PHP en un componente monolítico.

## Garantías implementadas

- Servidor TCP persistente con framing binario y TLS opcional.
- Sincronización periódica mediante mensajes JASE cifrados y firmados.
- Sharding determinista y selección de réplicas por rendezvous hashing.
- Escrituras por quorum de mayoría.
- Términos de liderazgo y fencing tokens monotónicos para rechazar líderes obsoletos.
- Snapshots verificables por SHA-256 y restauración atómica.
- Balanceo por capacidades, CPU, trabajos activos y latencia.
- Métricas agregables entre nodos.
- Harness configurable de carga y fallos inyectados.

## Prevención de split-brain

Una escritura coordinada requiere:

1. líder vivo registrado;
2. término electoral vigente;
3. mayoría de ACK de preparación;
4. fencing token monotónico vigente antes de aplicar localmente.

Un proceso con un token anterior es rechazado como `stale_fencing_token`. Esto protege recursos que validen el token. La seguridad completa depende de que todos los adaptadores de almacenamiento y workers respeten el fencing token.

## Servidor

```bash
export JAS_NODE_ID=node-a
export JAS_NODE_ENDPOINT=tcp://0.0.0.0:9100
bin/jas cluster:serve
```

TLS opcional:

```bash
export JAS_TLS_ENABLED=1
export JAS_TLS_CERT=/etc/jas/node-a.crt
export JAS_TLS_KEY=/etc/jas/node-a.key
bin/jas cluster:serve
```

JASE continúa cifrando y firmando el mensaje de aplicación incluso cuando se usa TLS externo.

## Sincronización

```bash
export JAS_SYNC_STREAMS=queue,objects,wal
export JAS_SYNC_INTERVAL=5
bin/jas cluster:sync
```

## Snapshots

```bash
bin/jas cluster:snapshot before-upgrade
bin/jas cluster:snapshots
```

## Carga y fallos

```bash
bin/jas load:fault 50000 0.15
```

El segundo argumento es la probabilidad de fallo inyectado por réplica. El proceso valida las cadenas hash de todos los shards al finalizar.

## Límite honesto

La implementación ofrece quorum, términos y fencing para un clúster JAS controlado. No afirma ser una implementación completa de Raft/Paxos ni sustituye pruebas de operación en múltiples centros de datos. Para despliegues críticos se deben añadir relojes monitoreados, almacenamiento de términos en hardware o servicio duradero, rotación de certificados, pruebas de partición de red y reglas de operación.
