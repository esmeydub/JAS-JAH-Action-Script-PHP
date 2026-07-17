# JAS — arquitectura unificada

JAS es un sistema de tipado y runtime escrito en PHP para crear aplicaciones web empresariales y gubernamentales. Su unidad de ejecución no es una secuencia rígida, sino la combinación **tipo + objeto + evento + acción + estado + capacidad**.

## Núcleo

- `JasTypeScript`: contratos de datos, alias, uniones, listas y estructuras estrictas.
- `src/JAS/Definition`: definición de aplicaciones, dominios y límites de dependencia.
- `src/JAS/Action`: grafos de acciones y scheduler cooperativo.
- `src/JAS/ObjectGraph`: objetos activos que reaccionan a eventos.
- `src/JAS/Protocol`: representación binaria JAS independiente del lenguaje.
- `src/JAS/Security`: SALK integrado en la firma de paquetes.
- `src/DataCore`: estado persistente e índices.
- `app/memory`: memoria Hot/Warm/Cold.

## Regla de compatibilidad

El núcleo funciona en PHP puro. C, C++, WebAssembly, V8 u otros runtimes son adaptadores opcionales. Todos deben hablar el protocolo binario JAS y validar SALK antes de ejecutar capacidades.

## Ejecución no lineal

El scheduler ejecuta nodos cuando sus dependencias están satisfechas. Los objetos activos enlazan eventos con acciones. Esto permite que un evento de entrada, una consulta, un worker nativo y una actualización de DataCore formen parte del mismo grafo sin depender del orden físico de un archivo PHP.

## Seguridad

Un paquete JAS v2 contiene versión, opcode, flags, timestamp, request ID, object ID, payload y firma HMAC-SHA256. Un paquete sin firma SALK válida no se decodifica.

## Aplicaciones empresariales y gubernamentales

Los límites de entrada deben declararse mediante contratos estrictos: sólo se aceptan
campos conocidos y tipos válidos. WAL, auditoría SALK, fencing, control de versión y
request IDs aportan trazabilidad y concurrencia segura. La autorización se expresa
mediante capacidades, evitando acoplar reglas de acceso a controladores HTTP.

Cada acción de producción declara tipos de entrada y salida, capacidad, auditoría y
política de ejecución. Una acción transaccional o encolada debe ser idempotente para
que los reintentos no dupliquen publicaciones, pagos, trámites o notificaciones.
