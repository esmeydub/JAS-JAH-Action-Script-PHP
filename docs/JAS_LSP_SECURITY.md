# Frontera de seguridad del servidor de lenguaje JAS

Estado: contratos L0 y documentos/posiciones L1 implementados. El bridge LSP todavía no existe y JAS aún no
se presenta como servidor LSP estándar.

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
| Escritura inesperada | LSP sólo devuelve `WorkspaceEdit`; el editor decide y aplica |

## Supuestos y pendientes explícitos

- La clave efímera por descriptor heredado se implementará junto con el servicio
  y el bridge; hoy sólo existe el contrato firmado reusable de JASB.
- `DocumentStore` y la conversión UTF-16 están implementados; sincronización
  incremental por rangos permanece deshabilitada hasta una ampliación posterior.
- El proceso `language:serve`, lifecycle y multiplexación son L2/L3.
- La validación C actual comprueba framing TLV y límites estructurales; el bridge
  añadirá UTF-8 estricto, nombres de campo y semántica antes de publicar binarios.
- No hay certificación externa. Fuzzing, sandbox por sistema operativo, revisión
  criptográfica y firma de distribución siguen siendo criterios de fases posteriores.
