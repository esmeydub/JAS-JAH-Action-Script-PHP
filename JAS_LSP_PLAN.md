# Plan normativo del servidor LSP de JAS

Estado: **en progreso; L0–L6 completadas, L7 es la siguiente acción**

## Objetivo y frontera inmutable

JAS proporcionará un servidor compatible con LSP mediante dos procesos:

```text
Editor ⇄ LSP 3.18 / JSON-RPC ⇄ jas-lsp-bridge (C++) ⇄ JASB ⇄ motor semántico JAS (PHP)
```

- JAS, DataCore y el motor semántico nunca reciben JSON.
- JSON-RPC existe exclusivamente en el bridge externo exigido por LSP.
- JASB es el único protocolo entre el bridge y PHP.
- El bridge no contiene inteligencia semántica ni accede a DataCore.
- Ningún mensaje puede ejecutar código, shell o un programa elegido por el editor.
- El bridge inicia PHP con ruta y argumentos fijos, sin shell y con entorno reducido.
- El editor aplica `WorkspaceEdit`; el servidor LSP no modifica archivos directamente.
- El bridge se distribuye como paquete externo y no convierte C++ o JSON en dependencias del núcleo JAS.

## Fase L0 — Modelo de amenazas y contratos

Estado: **completada**

- Documentar procesos, pipes, claves efímeras, workspace autorizado y límites de confianza.
- Definir opcodes JASB tipados para lifecycle, documentos, navegación, rename y diagnósticos.
- Separar requests con ID de notificaciones sin ID.
- Admitir IDs LSP string o enteros y correlacionarlos sin pérdida con JASB.
- Versionar contratos, errores, tamaños y compatibilidad.

Cierre verificado: `LanguageMessage`, `LanguagePayloadCodec` y
`LanguageProtocolCodec` reservan opcodes 600–691, validan campos/direcciones y
realizan round-trip firmado. Los SDK C/C++ comparten constantes y validan TLV
JASL; el modelo de amenazas está en `docs/JAS_LSP_SECURITY.md`.

## Fase L1 — Documentos y posiciones

Estado: **completada**

- Crear `DocumentStore` con URI, versión, contenido, hash y estado.
- El documento abierto recibido por `didOpen`/`didChange` es autoritativo y no se relee del disco.
- Aplicar primero sincronización completa y después incremental.
- Rechazar versiones antiguas, URI fuera del workspace, traversal y symlinks evasivos.
- Negociar `positionEncoding`; soportar obligatoriamente UTF-16 y probar Unicode multibyte.
- Limitar bytes, líneas, documentos abiertos y memoria total.

Cierre verificado: `DocumentStore` mantiene contenido autoritativo, versiones y
hashes con límites de documentos, bytes y líneas; confina URI `file` mediante
paths canónicos y rechaza traversal/symlinks externos. `LanguagePositionCodec`
convierte UTF-8, UTF-16 y UTF-32 sin dividir caracteres. El índice consume
overlays, archivos nuevos y diagnósticos sin guardar sin modificar el disco.

## Fase L2 — Servicio binario PHP

Estado: **completada**

Crear `php bin/jas language:serve --stdio` para:

- leer y escribir frames JASB acotados;
- autenticar la sesión local y validar firma, secuencia, opcode, versión y tamaño;
- mantener índice y documentos abiertos;
- ejecutar diagnósticos, hover, definición, referencias y rename;
- emitir notificaciones binarias de diagnósticos;
- no ejecutar definiciones PHP, comandos o shell.

Cierre verificado: `LanguageBinaryService` aplica lifecycle, sesión, replay,
reloj, codificación negociada y despacho semántico. `LanguageStdioServer` procesa
frames acotados; `bin/jas language:serve --stdio` exige clave SALK mediante
descriptor heredado. Un cliente binario obtiene diagnósticos, hover, definición,
referencias y plan de rename sobre documentos guardados/no guardados.

## Fase L3 — Bridge externo C++

Estado: **completada**

- Implementar dos pares de pipes: editor ⇄ C++ y C++ ⇄ PHP.
- Leer `Content-Length` exactamente y soportar frames parciales/consecutivos.
- Usar un parser JSON maduro enlazado estáticamente; no escribir uno casero.
- Permitir únicamente métodos y estructuras conocidos.
- Multiplexar respuestas y notificaciones asíncronas.
- Gestionar timeout, caída/reinicio controlado de PHP, stderr acotado y cierre del editor.
- Usar `execve`/`posix_spawn` o `CreateProcess` con argv fijo, nunca shell.

Cierre: `initialize`, `initialized`, `shutdown` y `exit` pasan contra un cliente LSP real.

Avance verificado: `sdk/cpp/lsp/jas_lsp_bridge.cpp` usa RapidJSON compilado
dentro del ejecutable, OpenSSL 3 para SALK HMAC-SHA256, clave efímera por pipe,
`fork`/`execl` con argv fijo y dos canales de stdio independientes. Un lector
asíncrono multiplexa respuestas/notificaciones PHP mientras el hilo principal
procesa frames LSP. `make -C sdk/cpp/lsp test` valida mensajes consecutivos y el
lifecycle JSON-RPC 2.0 real. El hardening vigente añade comparación constante,
UTF-8 estricto, rechazo de claves duplicadas, profundidad/elementos acotados,
máximo de 256 requests activos con ID único, paths canónicos, entorno vacío,
descriptores allowlisted, core dumps desactivados, `no_new_privs`, umask 077 y
stderr del hijo sin salida. Las pruebas negativas cubren framing byte a byte,
método ejecutable rechazado, ambigüedad de claves, Content-Length excesivo,
rutas inválidas y ausencia de fuga. La caída de PHP termina el bridge sin
reiniciar estado parcial; el cliente debe reiniciar la sesión completa. Cada
request tiene un deadline interno inmutable de 15 segundos y el lector vigila
tanto silencio como frames PHP parciales. La prueba compila una variante de 200
ms y demuestra que un backend deliberadamente colgado es terminado. L3 queda
cerrada con `JAS LSP BRIDGE SECURITY BOUNDARY: PASS`.

## Fase L4 — Capacidades mínimas

Estado: **completada**

Implementar en orden:

1. `initialize` e `initialized`;
2. `textDocument/didOpen`, `didChange` y `didClose`;
3. `textDocument/publishDiagnostics`;
4. `textDocument/hover`;
5. `textDocument/definition`;
6. `textDocument/references`;
7. `textDocument/prepareRename` y `rename`;
8. `shutdown` y `exit`;
9. `$/cancelRequest` y backpressure de solicitudes pendientes.

No se anunciará autocompletado, formato, code actions, símbolos o semantic tokens hasta implementarlos.

Cierre verificado: `tests/test_jas_lsp_capabilities.sh` inicia el bridge estándar
y valida documentos abiertos/cambiados/cerrados, diagnósticos push, hover,
definición, referencias, prepareRename, rename como `WorkspaceEdit`, shutdown y
exit. `$/cancelRequest` marca IDs activos y convierte su respuesta posterior en
`-32800`; PHP termina la operación de forma acotada pero su resultado cancelado
no llega al editor. La tabla mantiene 256 requests activos como máximo y la
prueba de presión demuestra que el 257 se rechaza localmente con `-32000`.
Ninguna operación LSP escribe o renombra archivos por sí misma.

## Fase L5 — Rename transaccional para el editor

Estado: **completada**

- Responder con `WorkspaceEdit`, nunca aplicar desde el servidor.
- Incluir `TextDocumentEdit` versionado y `RenameFile` sólo si el cliente anuncia `documentChanges` y `resourceOperations`.
- Añadir anotaciones únicamente cuando el cliente las soporte.
- Proporcionar fallback de cambios de texto sin prometer rename físico.
- Mantener el comando CLI `--apply` separado del protocolo LSP.

Cierre: un rename soportado actualiza referencias y archivo mediante una operación reversible del editor.

Cierre verificado: el bridge conserva `changes` para clientes legados. Si el
cliente anuncia `documentChanges`, agrupa `TextDocumentEdit` por URI e incluye
la versión abierta o `null` para archivos de disco. `RenameFile` sólo aparece
cuando `resourceOperations` contiene `rename`; las anotaciones sólo aparecen
con `changeAnnotationSupport`. Versiones contradictorias se rechazan. La prueba
simula la aplicación del editor, verifica referencias y archivo renombrado,
revierte toda la operación y recupera exactamente los hashes iniciales. El
bridge no escribe durante ninguna respuesta y el CLI `--apply` permanece
separado de LSP.

## Fase L6 — Seguridad y resiliencia

Estado: **completada**

- Límites de Content-Length, profundidad, strings, arrays, documentos y requests pendientes.
- UTF-8 estricto en JSON-RPC y conversión de posición acotada.
- Workspace canónico, defensa symlink/TOCTOU y rechazo de URI remotas por defecto.
- Proceso sin privilegios, sin red, filesystem mínimo y descriptores heredados allowlisted.
- Clave efímera entre bridge y PHP entregada por descriptor heredado, no por argumentos.
- Errores redactados, rate limiting y cancelación.
- Fuzzing del parser, framing, JASB, Unicode, lifecycle y reinicios.

Cierre: no hay ejecución, traversal, fuga sensible, crecimiento ilimitado ni caída persistente.

Cierre verificado: además de los límites de framing, árbol, strings, documentos
y 256 requests pendientes, el bridge aplica un token bucket de 250 mensajes por
segundo con ráfaga máxima de 512. El exceso se informa una sola vez y después se
descarta sin crear estado. Cada request conserva su timeout interno inmutable.

En Linux, el hijo PHP queda bajo `no_new_privs`, seccomp y Landlock antes de
`exec`: no puede crear sockets ni escribir en el workspace o fuera de él; sólo
puede leer el binario, runtime PHP, árbol JAS autorizado y workspace. Un kernel
sin Landlock o una ejecución como root hace fallar el arranque cerrado. La clave efímera sigue entrando
por el único descriptor adicional allowlisted.

Las pruebas sustituyen deliberadamente el backend, intentan sockets y escritura,
presionan timeout/backpressure/rate limiting y procesan 500 mensajes JSON-RPC
malformados o prohibidos antes de completar un lifecycle válido. Las suites
JASB/PHP aportan además corrupción binaria, replay, Unicode, paths y lifecycle.
`make -C sdk/cpp/lsp test` registra `JAS LSP BRIDGE SECURITY BOUNDARY: PASS` y
`JAS LSP PROLONGED FUZZ: PASS`.

## Fase L7 — Interoperabilidad y distribución

Estado: **pendiente; siguiente acción obligatoria**

- Probar contratos, JSON-RPC, integración completa y seguridad.
- Validar Neovim, Emacs/Eglot, Sublime LSP y al menos otro cliente configurable.
- Empaquetar Linux x86-64/ARM64, Windows x86-64 y macOS ARM64 sólo donde exista validación real.
- Publicar ejecutable, licencia, SBOM, SHA-256, firma del artefacto, procedencia y versiones compatibles.
- Buscar builds reproducibles; aplicar firma de código/notarización donde corresponda.

## Definición de terminado

El nombre oficial seguirá siendo `JAS Language Intelligence Engine` hasta que:

- un editor estándar complete el lifecycle LSP;
- cambios sin guardar produzcan diagnósticos;
- hover, definición y referencias funcionen con posiciones Unicode correctas;
- rename devuelva un `WorkspaceEdit` acorde con capacidades negociadas;
- JSON permanezca exclusivamente en el bridge externo;
- PHP y DataCore usen sólo JASB;
- suites PHP, C++, protocolo, integración, editores y seguridad pasen;
- exista al menos un binario firmado y reproduciblemente instalable.

Después podrá denominarse:

**JAS Language Server with an external C++ LSP compatibility bridge**.
