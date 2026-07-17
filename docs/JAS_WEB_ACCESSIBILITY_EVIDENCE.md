# Evidencia de accesibilidad de JAS Web

Fecha de ejecución: **2026-07-16**

Objetivo: **WCAG 2.2 nivel AA**

Página evaluada: `GET /publicacion?id=POST-1` de `examples/social_network.php`

Resultado: **aprobada para el alcance de la aplicación de referencia**

Esta evidencia cierra la revisión exigida por la fase 6. No constituye una
certificación externa ni permite afirmar que cualquier aplicación construida
con JAS sea accesible sin evaluar su contenido, flujos y personalizaciones.

## Entorno

- Chromium `149.0.7827.114`, perfil temporal vacío y accesibilidad forzada.
- Orca `50.1.2` con AT-SPI `2.60.3`.
- Viewports de `1280 × 800` y `320 × 640` CSS px.
- Aplicación servida localmente con PHP y sin sesión, extensiones ni datos de un
  perfil de navegador existente.

## Resultados por criterio

| Criterio | Comprobación | Resultado |
|---|---|---|
| 1.4.3 Contraste mínimo | 4 nodos de texto visibles medidos con estilos computados | 0 fallas |
| 1.4.10 Reflow | ancho de documento comparado con viewport a 320 CSS px | sin desbordamiento horizontal |
| 1.4.11 Contraste no textual | inspección del foco nativo oscuro sobre fondo claro | aprobado en el único control interactivo |
| 2.1.1 Teclado | navegación con `Tab` desde el documento | el enlace de salto recibe foco |
| 2.4.7 Foco visible | estilo computado y captura visual | `auto`, 1 px, color `rgb(16, 16, 16)` |
| 2.4.11 Foco no oculto | geometría, hit-test y viewport | visible, dentro del viewport y no obstruido |
| 2.5.8 Tamaño del objetivo | 1 objetivo revisado | 0 fallas; es un enlace de texto en línea contemplado por la excepción del criterio |
| 3.3.8 Autenticación accesible | revisión de alcance | no aplica: la referencia evaluada es pública y de solo lectura |
| 4.1.2 Nombre, función y valor | árbol de accesibilidad Chromium y lectura real Orca/AT-SPI | documento, `main`, enlace y encabezado reconocidos correctamente |

La revisión estructural adicional confirmó `lang="es"`, un único `main`, un
único `h1`, viewport con zoom permitido y el destino `#jas-main` del enlace de
salto. El árbol accesible de Chromium expuso 14 nodos útiles y encontró los roles
`main`, `heading` y `link` con nombre.

## Evidencia de lector de pantalla

Orca recibió la página mediante AT-SPI desde un Chromium aislado. El registro de
depuración confirmó, en modo de lectura continua:

```text
Active document: document web 'Publicación JAS'
SAY ALL PRESENTER: heading 'Publicación'
SPEECH GENERATOR: Results: Publicación, heading 1
SAY ALL PRESENTER: Speaking: Publicación
BRAILLE LINE: Publicación e1
FOCUS MANAGER: link 'Saltar al contenido principal'
BRAILLE LINE: Aplicación organizada con JAS — JAH Action Script PHP
```

El registro completo de Orca no se incorpora porque contiene eventos ajenos de
la sesión de escritorio. Las líneas anteriores son el extracto mínimo del
proceso aislado y no incluyen información de otras aplicaciones.

## Resultado reproducible del navegador

```text
standard=WCAG 2.2 AA
browser=Chromium 149.0.7827.114
title=Publicación JAS
lang=es
main_count=1
h1_count=1
skip_target=#jas-main
contrast_nodes_checked=4
contrast_failures=0
target_nodes_checked=1
target_failures=0
keyboard_focus_tag=A
keyboard_focus_class=jas-skip-link
keyboard_focus_visible=true
keyboard_focus_outline=auto:1px
keyboard_focus_outline_color=rgb(16, 16, 16)
keyboard_focus_unobscured=true
keyboard_focus_inside_viewport=true
ax_has_main=true
ax_has_heading=true
ax_has_link=true
ax_named_publication=true
desktop_horizontal_overflow=false
mobile_horizontal_overflow=false
authentication_check=not_applicable_public_read_only_reference
```

Huellas SHA-256 de los artefactos visuales generados durante la revisión:

```text
57840407d7edef439ae23d82c7f16fd77a0f66e7003010742589f5ca38d27760  desktop-1280.png
cc187605afd53b5becdd0ac4ab7b61bde7344820ba6a4150f077717b11169cc3  mobile-320.png
46ee92101b6e33887a770554bd5f4bfdf7fadcfb55dea06f0196973e5464cb4e  report.txt
```

## Repetición de la revisión

1. Ejecutar `php -S 127.0.0.1:8097 examples/social_network.php` desde la raíz.
2. Abrir la URL evaluada en un perfil limpio de Chromium con accesibilidad
   habilitada.
3. Repetir la navegación por teclado, las mediciones a 1280 y 320 CSS px y la
   lectura continua con Orca/AT-SPI.
4. Ejecutar `php tests/test_jas_accessibility.php` para las comprobaciones
   positivas y el documento adversarial.
5. Ejecutar `php tests/run_all.php`; el cierre sólo es válido si termina en
   `JAS SUITE: PASS`.

El caso de autenticación queda deliberadamente como no aplicable a esta página.
Cualquier referencia futura que incorpore login deberá añadir evidencia propia
para 3.3.8 antes de considerarse completa.
