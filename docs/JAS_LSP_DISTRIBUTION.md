# JAS Language Server — distribución externa

Este paquete contiene el puente de compatibilidad externo de JAS para el
Language Server Protocol. El editor habla JSON-RPC únicamente con el binario
C++; el motor PHP recibe JASB/JASL firmado y DataCore nunca recibe JSON.

## Plataforma verificada

- Linux x86-64, kernel con Landlock y seccomp.
- PHP 8.2 o posterior con Sodium.
- El binario C++ está enlazado estáticamente y no requiere instalar OpenSSL,
  RapidJSON ni un runtime C++ en el equipo destino.
- La instalación JAS continúa siendo necesaria y se indica mediante `JAS_ROOT`.

No se publican todavía binarios ARM64, Windows o macOS: el código contempla
Linux ARM64, pero ningún artefacto de esas plataformas se presenta como validado
sin haber pasado allí la misma suite.

## Verificación e instalación

Conserve juntos el archivo, su `.sha256` y, para una entrega firmada, `.sig` y
`.pem`. Desde el repositorio JAS:

```sh
sdk/cpp/lsp/verify-package.sh jas-lsp-1.3.1-linux-x86_64.tar.gz \
  jas-lsp-1.3.1-linux-x86_64.tar.gz.sig \
  jas-lsp-1.3.1-linux-x86_64.tar.gz.pem
tar -xzf jas-lsp-1.3.1-linux-x86_64.tar.gz
jas-lsp-1.3.1-linux-x86_64/install.sh "$HOME/.local"
export JAS_ROOT=/ruta/confiable/JAS-JAH-Action-Script-PHP
```

El verificador valida SHA-256, firma Ed25519 cuando se proporciona, rutas del
archivo, estructura, SBOM SPDX, procedencia y que el ejecutable sea estático.
La clave pública adjunta sólo identifica una entrega si su huella fue distribuida
por un canal institucional independiente. Una clave incluida junto a un archivo
no crea confianza por sí sola.

Los artefactos construidos en GitHub reciben además una atestación Sigstore
ligada al repositorio, workflow y commit. Se verifica con:

```sh
gh attestation verify jas-lsp-1.3.1-linux-x86_64.tar.gz \
  --repo esmeydub/JAS-JAH-Action-Script-PHP
```

## Inicio seguro

El comando recibe sólo el workspace autorizado:

```sh
JAS_ROOT=/ruta/JAS jas-lsp /ruta/proyecto
```

El launcher resuelve PHP y entrega al bridge rutas fijas. Ningún mensaje del
editor puede elegir un ejecutable, argumento de shell o acceso DataCore.

## Clientes

La suite reproduce los perfiles de capacidades y el lifecycle de cuatro clientes:

| Cliente | Perfil verificado | Configuración del comando |
|---|---|---|
| Neovim 0.11+ | UTF-16, `documentChanges`, rename y anotaciones | `jas-lsp /ruta/proyecto` |
| Emacs/Eglot | defaults LSP y UTF-16 | `jas-lsp /ruta/proyecto` |
| Sublime LSP | UTF-16, cambios documentales y rename | `jas-lsp /ruta/proyecto` |
| Helix | preferencia UTF-8 y fallback UTF-16 | `jas-lsp /ruta/proyecto` |

En todos los casos configure el lenguaje PHP y pase el comando como una lista de
argumentos, nunca mediante `sh -c`. Establezca `JAS_ROOT` en el entorno controlado
del editor. El test reproducible valida los perfiles de protocolo, no afirma que
las aplicaciones gráficas ausentes del runner hayan sido operadas manualmente.

Neovim tiene además una prueba con el editor real. CI descarga el tarball oficial
0.12.4, verifica su SHA-256 fijado, inicia JAS, abre un documento, ejecuta hover y
cierra el lifecycle. Eglot, Sublime LSP y Helix conservan evidencia de perfil de
protocolo hasta disponer de sus ejecutables en runners gobernados.

## Construcción reproducible y firma

```sh
SOURCE_DATE_EPOCH=1700000000 JAS_SOURCE_REVISION=commit \
  sdk/cpp/lsp/package.sh dist
```

Dos builds con el mismo código, toolchain, arquitectura y epoch producen el
mismo `.tar.gz`. El archivo incluye SBOM SPDX tag-value y procedencia JAS nativa.
La firma es externa para conservar reproducible el paquete:

```sh
openssl genpkey -algorithm ED25519 -out /ruta/segura/jas-release.pem
chmod 0600 /ruta/segura/jas-release.pem
JAS_LSP_SIGNING_KEY=/ruta/segura/jas-release.pem sdk/cpp/lsp/package.sh dist
```

La clave privada nunca debe guardarse en el repositorio ni dentro del paquete.
