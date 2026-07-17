# Frontera de seguridad del servidor de lenguaje JAS

Estado: L0–L7 completadas; bridge, lifecycle, capacidades, aislamiento, timeout,
cancelación, backpressure, rename, hardening, perfiles de clientes y distribución
Linux x86-64 verificados. El componente externo puede presentarse como servidor
JAS compatible con LSP; el núcleo PHP continúa sin JSON.

## Procesos y zonas de confianza

```text
Zona no confiable                 Frontera externa             Núcleo confiable
Editor / plugin / documento  ⇄  jas-lsp-bridge C++  ⇄ JASB ⇄ PHP language service
        JSON-RPC                         JSON                TLV JASL, sin JSON
```

El editor, su configuración, URIs, contenido y todos los mensajes JSON-RPC son
entrada no confiable. El bridge será un traductor acotado y no tendrá acceso a
DataCore, claves de negocio, auditoría institucional ni ejecución de acciones.
El proceso PHP vuelve a validar el paquete y el contrato aunque el bridge ya lo
haya hecho.

## Responsabilidades

### Editor

- conserva y aplica cambios de workspace;
- administra documentos abiertos y versiones;
- nunca obtiene acceso directo a JASB o DataCore.

### Bridge C++ externo

- implementa exclusivamente framing LSP, parser JSON-RPC, negociación y mapeo;
- permite sólo los métodos anunciados;
- convierte valores al TLV binario `JASL` y los encapsula en JASB;
- inicia PHP mediante argv fijo, sin shell, o se conecta a un proceso local ya gobernado;
- mantiene dos pares de pipes y separa stdout de stderr;
- no interpreta símbolos, no modifica archivos y no ejecuta comandos del editor.

### Servicio PHP

- valida firma, versión, opcode, correlación, sesión, tamaño y contrato;
- mantiene índice y documentos abiertos;
- realiza diagnósticos, hover, definición, referencias y planificación de rename;
- propone cambios; nunca los aplica en una llamada LSP;
- no carga ni ejecuta los archivos PHP analizados.

El proceso se inicia como `php bin/jas language:serve --stdio <workspace>`. La
clave SALK de 32 bytes debe llegar por el descriptor heredado indicado en
`JAS_LANGUAGE_KEY_FD`; no se acepta por argv, archivo de proyecto ni variable de
entorno con el secreto. stdout queda reservado exclusivamente para frames y los
errores de arranque se redactan en stderr.

## Contrato binario L0

Los opcodes 600–691 están reservados para el servicio de lenguaje. El payload
empieza con `JASL`, versión 1, y contiene valores TLV canónicos:

- null, boolean, entero firmado de 32 bits y string UTF-8;
- listas y mapas con profundidad máxima 16;
- máximo 4,096 elementos por contenedor;
- claves ASCII allowlisted y ordenadas canónicamente;
- máximo 8 MiB por payload de lenguaje.

`LanguageMessage` distingue request, notification, response y error. Los IDs
externos sólo aparecen en requests/responses; las notificaciones no aceptan ID.
El `requestId` de JASB es una correlación interna independiente. Los contratos
validan campos exactos, versiones, posiciones, codificaciones, cambios completos
y diagnósticos antes de procesarlos.

Los SDK C/C++ exponen los mismos opcodes y validan el árbol TLV sin asignaciones.
La firma SALK sigue siendo obligatoria antes de confiar en la semántica; el
validador C estructural por sí solo no autentica el paquete.

## Métodos permitidos en el perfil inicial

- `initialize`, `initialized`, `shutdown`, `exit`;
- `textDocument/didOpen`, `didChange`, `didClose`;
- `textDocument/hover`, `definition`, `references`;
- `textDocument/prepareRename`, `rename`;
- `textDocument/publishDiagnostics`.

Cualquier método u opcode diferente se rechaza. El perfil inicial anuncia
cambios completos; sincronización incremental se habilitará sólo después de
implementar posiciones y rangos Unicode en L1.

## Amenazas y controles obligatorios

| Amenaza | Control |
|---|---|
| Ejecución de shell | binario/ruta PHP y argv fijos; nunca `system`, shell o argumentos derivados del mensaje |
| Traversal o symlink evasivo | workspace canónico y resolución confinada antes de cualquier lectura |
| Payload o árbol explosivo | límites de Content-Length, JASB, TLV, profundidad, elementos y memoria total |
| Desincronización | versión monotónica por documento y estado autoritativo en memoria mientras esté abierto |
| Confusión Unicode | UTF-8 estricto y conversión explícita de UTF-16/UTF-8 negociada |
| Replay o mensaje cruzado | clave efímera por sesión, secuencia, correlation ID y session ID |
| Bridge comprometido | revalidación completa en PHP, allowlist de opcodes y ausencia de acceso a DataCore |
| Fuga en errores | códigos estables, mensajes acotados y stderr redactado |
| Agotamiento por concurrencia | máximo de requests pendientes, cancelación, timeout y backpressure |
| Ráfaga de mensajes | token bucket interno, respuesta única y descarte sin asignar estado |
| Red desde PHP | seccomp deniega sockets y operaciones de red antes de ejecutar PHP |
| Escritura inesperada | LSP sólo devuelve `WorkspaceEdit`; Landlock deja runtime, JAS y workspace en sólo lectura |

## Supuestos y pendientes explícitos

- El bridge L3 crea la clave efímera, entrega exactamente 32 bytes por pipe y
  cierra su extremo; el secreto no aparece en argv ni en archivos.
- `DocumentStore` y la conversión UTF-16 están implementados; sincronización
  incremental por rangos permanece deshabilitada hasta una ampliación posterior.
- `language:serve`, spawn fijo, framing JSON-RPC, multiplexación asíncrona,
  timeout y fallo cerrado del backend están implementados.
- La validación C actual comprueba framing TLV y límites estructurales; el bridge
  añadirá UTF-8 estricto, nombres de campo y semántica antes de publicar binarios.
- No hay certificación externa. El aislamiento L6 está implementado y probado en
  Linux; revisión criptográfica independiente y firma de distribución siguen
  siendo criterios de fases posteriores.

## Controles implementados en el bridge Linux

- RapidJSON usa validación UTF-8 y parsing iterativo; una segunda caminata limita
  profundidad, elementos totales, contenedores, strings y claves duplicadas.
- Sólo once métodos LSP pueden producir opcodes JASB; `executeCommand` y cualquier
  método desconocido se rechazan localmente.
- SALK se verifica con comparación constante. También se comprueban versión,
  flags, timestamp, opcode, sesión, longitudes e ID de respuesta activo.
- Hay un máximo de 256 requests simultáneos y no se admiten IDs activos repetidos.
- Un token bucket compilado admite 250 mensajes por segundo y ráfaga 512. El
  primer exceso recibe `-32001`; los siguientes se descartan hasta recuperar
  capacidad, sin crear requests ni reenviar trabajo a PHP.
- `$/cancelRequest` sólo puede marcar un ID activo; nunca produce un opcode ni
  ejecuta una orden en PHP. La respuesta posterior se sustituye por `-32800` y
  la operación interna permanece limitada por el timeout fail-closed.
- `RenameFile`, versiones y anotaciones se emiten únicamente tras negociación
  explícita. Las rutas proceden del plan JASB validado por PHP; el bridge no
  acepta rutas de rename elegidas por el editor ni aplica el `WorkspaceEdit`.
- PHP se ejecuta con paths canónicos, argv fijo, entorno vacío, FDs mínimos,
  umask 077, core dumps deshabilitados y `no_new_privs`; stderr va a un sumidero
  acotado para que un error no filtre rutas, secretos o contenido al editor.
- El hijo rechaza ejecutarse con UID o GID efectivo cero; el bridge no debe
  instalarse ni iniciarse como root.
- Antes de `exec`, seccomp deniega sockets, conexión, escucha y envío/recepción.
  También bloquea `io_uring`, creación de procesos, `execveat`, BPF y acceso a
  memoria de otros procesos para cerrar rutas alternativas de evasión.
  Landlock permite leer únicamente el ejecutable/runtime PHP, el árbol JAS y el
  workspace canónicos; no concede ningún derecho de escritura. La ausencia de
  Landlock en Linux aborta el hijo en vez de degradar silenciosamente.
- El material de clave se limpia de la memoria del bridge al transferirse y al
  destruir el canal. Los mensajes externos de error son estables y redactados.

Estos controles reducen la superficie, pero no equivalen a una certificación.
La caída del servicio PHP cierra la sesión completa; no se intenta reconstruir
silenciosamente documentos ni requests parciales. El cliente puede iniciar un
bridge nuevo desde cero. Cada request expira a los 15 segundos mediante un valor
compilado que el editor no puede ampliar; el lector también limita frames PHP
parciales. Las pruebas L6 cubren un backend sustituido, escritura, sockets,
ráfagas y 500 mensajes malformados/prohibidos seguidos de lifecycle válido.
La revisión criptográfica y el penetration test independientes pertenecen a la
Fase 9 y no se sustituyen por estas pruebas internas.
