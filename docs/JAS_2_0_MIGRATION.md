# Migración a JAS 2.0

Esta guía acompaña la congelación de la API pública 2.0. JAS 2.0 conserva PHP
8.2+ como mínimo, DataCore como persistencia autoritativa y formatos PHP/JAH y
JASB; no introduce JSON, JavaScript, Node ni Composer en el núcleo.

## Antes de actualizar

1. Conserve una copia inmutable del proyecto y del runtime desplegado.
2. Cree un backup cifrado, verifíquelo y restaure en un directorio aislado.
3. Ejecute `php bin/jas app:compat <anterior> <nuevo>`.
4. Corrija toda ruptura y revise manualmente las adiciones compatibles.
5. Ejecute `format --check`, `analyze`, pruebas específicas y la suite completa.
6. Despliegue primero en un entorno canario y conserve rollback.

## Cambios principales desde 1.x

- `InstitutionalIdentityService` con DataCore reemplaza a `AuthStore` para código
  nuevo. Los roles son capacidades explícitas y se evalúan dinámicamente.
- DataCore es la única fuente de verdad. SQL sólo opera como mirror de salida o
  importación gobernada; ninguna escritura SQL modifica DataCore directamente.
- Acciones con efectos transaccionales requieren idempotencia, auditoría y
  contratos de entrada/salida definidos.
- Las definiciones generadas son literales PHP estrictos y se analizan sin
  ejecutarse. Listas tipadas usan descriptores como `PostView[]`.
- Los fallos públicos usan códigos de diagnóstico estables y redacción. No base
  automatizaciones en el texto humano del error.
- El antiguo “LSP” CLI se describe como Language Intelligence Engine. La
  compatibilidad LSP estándar está en un bridge C++ externo y experimental; no
  forma parte del runtime PHP.

## Patrón recomendado

Migre un incremento vertical a la vez:

```text
entrada → tipo → capacidad → acción → DataCore → evento/auditoría → salida
```

No acceda a una colección de otro dominio desde la interfaz. Declare la
dependencia y llame a su servicio o acción pública. Separe colas críticas para
que feed, moderación y notificaciones tengan capacidad operativa independiente.

## Rupturas que `app:compat` detecta

- eliminación de dominios, acciones, tipos o eventos;
- cambio de prefijo o dependencia eliminada;
- cambio de entrada, salida, capacidad, auditoría, idempotencia o evento;
- eliminación/cambio de campos o adición de un campo obligatorio;
- reutilización de una versión de evento con otro payload.

Un campo opcional nuevo produce advertencia revisable y no una ruptura. Nunca
ignore un reporte incompatible sólo porque la aplicación inicia.

## Rollback

Detenga nuevas escrituras, conserve la evidencia, restaure el backup verificado
en un árbol vacío y vuelva a la versión anterior del código. Después verifique
DataCore, journals, auditoría, sesiones y colas antes de reabrir tráfico. Una
restauración local exitosa no reemplaza un simulacro independiente del entorno
real ni una revisión externa de seguridad.
