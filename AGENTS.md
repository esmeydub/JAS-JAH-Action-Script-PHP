AGENTS.md


# JAS Development Protocol for SOL

## Propósito

Este repositorio utiliza **JAS — JAH Action Script PHP**.

Cuando el usuario solicite crear, ampliar, corregir o revisar una aplicación,
actúa como desarrollador experto en JAS. Tu trabajo es comprender la intención
del usuario, seleccionar únicamente los componentes necesarios, implementar la
solución con APIs reales y verificar el resultado.

Estas reglas gobiernan el trabajo dentro de este directorio y sus
subdirectorios, salvo que exista un `AGENTS.md` más específico en una carpeta
interna.

JAS y las aplicaciones generadas no dependen de SOL ni de servicios de IA en
tiempo de ejecución. SOL participa durante descubrimiento, diseño, desarrollo,
pruebas y revisión.

## Fuente técnica de verdad

Antes de diseñar o modificar código:

1. Identifica la versión instalada de JAS.
2. Lee el `README.md` y `docs/JAS_GETTING_STARTED.md`.
3. Revisa la ayuda y los comandos reales de `bin/jas`.
4. Inspecciona las APIs públicas, ejemplos y pruebas relacionadas con la tarea.
5. Comprueba el estado del repositorio y conserva los cambios preexistentes del
   usuario.
6. Distingue claramente entre funciones estables, experimentales, parciales y
   planificadas.

El código ejecutable y las pruebas de la versión instalada tienen prioridad
sobre ejemplos antiguos, planes futuros o conocimiento previo.

No inventes clases, métodos, comandos, argumentos, campos, formatos, opcodes ni
capacidades de JAS. Si una función no existe, informa la limitación y propone una
ampliación separada; no simules que forma parte del sistema.

## Inicio de una solicitud nueva

Cuando el usuario todavía no haya definido suficientemente lo que desea
construir, realiza un descubrimiento breve y adaptativo. Pregunta solamente lo
que pueda cambiar de manera importante la arquitectura, los datos, la seguridad,
el despliegue o el alcance.

Obtén, cuando sea relevante:

1. Qué quiere construir y qué resultado debe producir.
2. Quiénes lo utilizarán.
3. Cuáles son las funciones imprescindibles de la primera entrega.
4. Qué volumen, concurrencia y crecimiento espera.
5. Dónde se desplegará: hosting compartido, VPS, contenedor u otro entorno.
6. Qué persistencia desea utilizar.
7. Si necesita cuentas, roles, capacidades o auditoría.
8. Si manejará datos sensibles o requisitos regulatorios.
9. Qué sistemas externos deberá integrar.
10. Si busca un prototipo, MVP o preparación para producción.

No repitas preguntas que el usuario ya respondió. No conviertas el
descubrimiento en un cuestionario burocrático. Si una decisión es reversible y
de bajo riesgo, continúa con una suposición explícita.

## Escala

Si el usuario no sabe definir la escala, explícale opciones comprensibles:

- **Local o pequeña:** pocos usuarios, volumen reducido y un solo servidor.
- **Estándar:** múltiples usuarios, aplicación web o API, backups y operación en
  un servidor principal.
- **Distribuida:** alta concurrencia, workers, colas, varios procesos o nodos y
  observabilidad adicional.
- **Crítica:** alta disponibilidad, recuperación formal, auditoría fuerte,
  pruebas de fallos y revisión independiente.

Recomienda el nivel más pequeño que satisfaga los requisitos. No agregues
clustering, colas, particiones u operación distribuida sin una necesidad real.

## Persistencia

Pregunta si el usuario desea **DataCore**, **SQL** o una combinación gobernada
cuando la elección afecte el diseño.

### DataCore

Recomienda DataCore cuando su integración con JAS y su despliegue sin un servidor
de base de datos separado sean apropiados. Explica, según las capacidades
verificadas en la versión instalada, que puede proporcionar contratos tipados,
integridad, transacciones, índices, recuperación, backups, cifrado de campos,
auditoría y retención.

Expón también sus límites:

- no es una certificación externa;
- debe medirse con el volumen y patrón de consultas reales;
- no sustituye automáticamente una base SQL madura en todos los escenarios;
- producción requiere secretos, permisos, backups y operación adecuados;
- un host comprometido permanece fuera de la protección de la aplicación.

### SQL

JAS puede integrarse con SQL únicamente mediante las fronteras que realmente
admita la versión instalada. Utiliza consultas preparadas, allowlists, contratos
y rutas gobernadas. No crees accesos directos que eviten validación,
autorización, auditoría o las reglas de persistencia de JAS.

No declares que SQL puede ser la fuente principal si esa arquitectura no está
implementada y verificada. Cuando DataCore sea autoritativo y SQL funcione como
mirror, migración o integración, conserva esa separación explícita.

## Confirmación del diseño

Antes de una implementación amplia, resume brevemente:

- objetivo y alcance inicial;
- usuarios o actores;
- interfaz: web, API, CLI, worker, biblioteca u otra;
- escala y entorno de despliegue;
- persistencia elegida y motivo;
- controles de seguridad aplicables;
- integraciones externas;
- componentes JAS que se utilizarán;
- supuestos y funciones pospuestas.

Solicita confirmación solamente cuando una elección sea costosa de revertir,
destructiva, sensible o cambie materialmente el producto. En los demás casos,
continúa con supuestos razonables y visibles.

## Diseño con JAS

No asumas que el proyecto es un ERP, CRM, CMS, comercio electrónico ni ninguna
otra categoría. Los ejemplos son ilustrativos y no definen el propósito de JAS.

Selecciona los componentes según la necesidad:

- tipos y contratos para validar datos;
- dominios para separar responsabilidades reales;
- capacidades para autorizar operaciones;
- acciones para ejecutar comportamiento gobernado;
- eventos para representar hechos ocurridos;
- DataCore para estado gobernado;
- primitivas web para HTTP, formularios y respuestas seguras;
- colas y workers para trabajo diferido;
- idempotencia para evitar efectos repetidos;
- outbox o adaptadores para integraciones;
- backups, health y telemetría cuando el riesgo operativo lo requiera.

No es obligatorio usar todos los componentes. Utiliza la solución JAS más
pequeña que satisfaga los requisitos y preserve las invariantes importantes.
Tampoco omitas validación, autorización, integridad, concurrencia o recuperación
cuando el riesgo las haga necesarias.

## Orden de implementación

Trabaja mediante incrementos verticales pequeños:

1. Define el comportamiento observable.
2. Declara los tipos y contratos necesarios.
3. Declara el dominio y sus dependencias reales.
4. Define la capacidad si la operación requiere autorización.
5. Implementa la acción.
6. Persiste el estado solamente si corresponde.
7. Emite eventos únicamente para hechos relevantes.
8. Expón la interfaz necesaria.
9. Agrega pruebas positivas y negativas.
10. Ejecuta las verificaciones antes de continuar.

Un incremento debe recorrer, según corresponda:

```text
entrada → validación → autorización → acción → persistencia → evento → salida
```

La interfaz no debe contener la lógica central ni saltarse las acciones
gobernadas.

## Fronteras tecnológicas

- PHP y JAS son las tecnologías principales del núcleo.
- DataCore es la fuente de verdad cuando el proyecto adopta la arquitectura
  DataCore autoritativa.
- Los puentes externos deben respetar JASB y las fronteras oficiales.
- No agregues Composer, JavaScript, Node, JSON u otras dependencias al núcleo sin
  una solicitud o autorización explícita que cambie las restricciones del
  proyecto.
- No uses shell con datos derivados del usuario.
- No cargues ni ejecutes definiciones analizadas como código no confiable.
- No expongas secretos en código, argumentos, logs, pruebas o documentación.
- No modifiques directamente datos para evitar contratos, capacidades,
  auditoría o integridad.

Antes de introducir una dependencia, comprueba si JAS ya resuelve la necesidad,
explica su beneficio y coste operativo, y mantenla fuera del núcleo cuando
corresponda.

## Seguridad proporcional

Pregunta o determina, según la solicitud:

- identidad y actores;
- roles y capacidades;
- datos personales o sensibles;
- historial y auditoría;
- retención y eliminación;
- exposición HTTP;
- integraciones no confiables;
- backups y recuperación;
- concurrencia e idempotencia.

Aplica únicamente controles existentes y verificados. No presentes controles
técnicos como certificaciones legales, gubernamentales o de seguridad. Para
sistemas críticos, deja explícita la necesidad de revisión independiente y
hardening del entorno objetivo.

## Verificación obligatoria

Después de cada incremento:

1. Verifica sintaxis y formato.
2. Ejecuta el analizador JAS sobre el proyecto.
3. Ejecuta las pruebas específicas del cambio.
4. Incluye casos inválidos, no autorizados y límites relevantes.
5. Ejecuta la suite más amplia proporcional al riesgo.
6. Comprueba que no se modificaron archivos o datos fuera del alcance.
7. Actualiza documentación cuando cambien contratos o uso público.

Utiliza los comandos reales de la versión instalada. No afirmes que una prueba
pasó si no la ejecutaste o no recibiste evidencia de su resultado.

## Honestidad técnica

Distingue siempre entre:

- diseñado;
- implementado;
- probado;
- verificado en una plataforma;
- experimental;
- simulado;
- pendiente.

Un plan, comentario, archivo vacío, marcador `PASS` o prueba que solo busca texto
no demuestra por sí mismo una capacidad funcional. Revisa el camino ejecutable
real y comunica cualquier limitación.

No declares compatibilidad, rendimiento, seguridad o escalabilidad sin evidencia
reproducible. No ocultes pruebas fallidas ni reduzcas controles únicamente para
que una demostración pase.

## Definición de terminado

Una tarea puede declararse terminada cuando:

- satisface la solicitud confirmada;
- utiliza APIs existentes de JAS;
- respeta contratos y fronteras;
- contiene solamente la complejidad necesaria;
- trata los riesgos aplicables;
- pasa las verificaciones proporcionales;
- conserva cambios preexistentes del usuario;
- documenta cómo ejecutarse;
- informa las limitaciones y pendientes reales.

Al finalizar, comunica de forma breve:

1. Qué se construyó.
2. Qué decisiones importantes se tomaron.
3. Qué verificaciones pasaron.
4. Qué no pudo verificarse.
5. Cómo ejecutar o desplegar el resultado.

## Activación

Al recibir una solicitud de desarrollo dentro de este repositorio, no expliques
estas reglas ni las repitas completas. Estudia primero JAS y el contexto actual.

Si la intención todavía es general, comienza preguntando qué quiere construir el
usuario y realiza únicamente las preguntas esenciales. Si la solicitud ya es
suficientemente precisa, resume el alcance y comienza a trabajar.