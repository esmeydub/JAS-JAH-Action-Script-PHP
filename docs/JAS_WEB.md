# JAS Web

JAS Web conecta rutas HTTP con acciones gobernadas. No existen controladores que
puedan omitir tipos, capacidades, WAL o auditoría.

```php
$router = (new Router($runtime))
    ->middleware(new SecurityHeadersMiddleware())
    ->middleware(new CsrfMiddleware(fn() => $_SESSION['csrf']))
    ->middleware(new RateLimitMiddleware($store, $identityResolver));
```

Las rutas relacionadas se organizan con prefijos y middleware heredable. Los
grupos pueden anidarse y el middleware de una ruta no se filtra hacia rutas
hermanas:

```php
$router->group('/api', [$apiSecurity], function (Router $api): void {
    $api->group('/v1', [$institutionPolicy], function (Router $v1): void {
        $v1->route(
            'GET',
            '/ciudadanos/{id}',
            'ciudadano.consultar',
            $render,
            'api.ciudadanos.show',
            [$auditMiddleware],
        );
    });
});
```

El `Request` que recibe el renderer y el middleware de ruta incluye los atributos
`route_name`, `route_template` y `route_action`. Un grupo sólo acepta prefijos
estáticos: los parámetros pertenecen a la ruta y siempre terminan en el contrato
de su acción gobernada.

## Cookies tipadas

`SecureCookieJar` exige una definición antes de emitir o leer una cookie. Los
valores se validan por tipo, se cifran y autentican con `KeyRing`, incluyen
emisión y expiración dentro del sobre, y admiten rotación mediante identificador
de llave. El navegador recibe siempre prefijo `__Host-`, `Path=/`, `Secure`,
`HttpOnly` y `SameSite=Strict` o `Lax`:

```php
$cookies = (new SecureCookieJar($keyRing))
    ->define('identity', 'identifier', 900)
    ->define('page_size', 'positive-int', 3600, 'Lax');

$response = Response::html($page)
    ->withCookie($cookies->issue('identity', 'USER-1'));

$identity = $cookies->read('identity', $_COOKIE);
```

La cookie no contiene JSON, no acepta objetos serializados y una alteración,
cambio de propósito, vencimiento o contrato distinto se rechaza antes de
entregar el valor a la aplicación. Las cookies sensibles no ofrecen opciones
para desactivar `Secure` o `HttpOnly`.

## Uploads bajo custodia

`UploadVault` no confía en `Content-Type`, tamaño, nombre ni ruta enviados por el
navegador. `UploadedFile::fromPhpUpload()` exige un upload HTTP real; el archivo
se copia primero a un staging privado con límite incremental y desde esa copia
estable se calcula MIME mediante `finfo`, tamaño y SHA-256.

```php
$types = new TypeRegistry();
UploadVault::defineTypes($types);
UploadVault::configureDatabase($database);

$policy = new UploadPolicy(
    'citizen-documents',
    ['application/pdf', 'image/png'],
    10 * 1024 * 1024,
);

$upload = UploadedFile::fromPhpUpload($_FILES['document']);
$record = $vault->store($upload, $identityId, $policy, $requestId);
```

El escáner implementa `UploadScanner` y es obligatorio. Debe fallar cerrado si
el motor antimalware no está disponible. Después de aprobarlo, el contenido se
cifra por bloques con propósito e índice independientes; el archivo queda con
modo `0600` fuera del document root. DataCore conserva metadatos tipados,
cifrados y con lookup opaco del propietario. La lectura verifica autorización
del propietario, número de bloques, autenticidad criptográfica, tamaño y hash.

HTML, SVG, JavaScript y tipos PHP ejecutables están prohibidos aun si alguien
intenta agregarlos a una política. El nombre original sólo es metadato cifrado y
nunca participa en una ruta de disco.

## Layouts, tablas, paginación y errores

`Layout` ofrece únicamente los slots semánticos `header`, `navigation`, `main`,
`aside` y `footer`. `main` es obligatorio, cada slot se declara una sola vez y el
resultado incluye enlace para saltar contenido y landmarks accesibles:

```php
$layout = (new Layout('Navegación de trámites'))
    ->slot('header', $institutionHeader)
    ->slot('navigation', $primaryNavigation)
    ->slot('main', $content)
    ->slot('footer', $institutionFooter);

$response = Response::html(new Page('Trámites', $layout));
```

`DataTable` exige caption, contrato de columnas y filas completas. Produce
encabezados `scope="col"`, permite declarar un encabezado de fila y envuelve la
tabla en una región navegable cuando requiere desplazamiento. Los escalares se
escapan; contenido enriquecido debe llegar como `Component` o `SafeHtml` creado
por componentes JAS.

`Pagination` recibe un nombre de ruta registrado, no una URL concatenada. Valida
rangos, preserva filtros escalares, reemplaza cualquier `page` recibido y genera
`aria-current`, relaciones anterior/siguiente y una ventana acotada de enlaces.

`Response::error()` sólo acepta estados públicos definidos y construye una
`ErrorPage` accesible. El router la usa para 400, 404, 422 y 500; las excepciones,
rutas internas, credenciales y trazas nunca se incorporan al HTML. El ID de
petición se escapa y sirve como referencia para buscar el detalle en auditoría.

## Formularios avanzados

`FormControl` especializa campos sin abandonar el contrato del `TypeRegistry`.
JAS incorpora los tipos `date`, `datetime` y `timezone`; una fecha imposible o
un timestamp normalizado por PHP no supera la validación estricta.

```php
$form = new Form(
    $types,
    'GovernmentAppointment',
    '/appointments',
    $csrf,
    controls: [
        'birth_date' => FormControl::date('1900-01-01', '2026-12-31'),
        'appointment_at' => FormControl::dateTime('timezone'),
        'timezone' => FormControl::timezone([
            'America/Mexico_City' => 'Ciudad de México',
            'UTC' => 'Tiempo universal',
        ]),
        'role' => FormControl::select($allowedRoles),
        'notifications' => FormControl::select($channels, multiple: true),
        'attachment' => FormControl::file($uploadPolicy),
    ],
);

$result = $form->submit($_POST, $_FILES);
```

Los `datetime-local` exigen el campo de zona declarado y se convierten a UTC
antes de validar el contrato. Selects simples y múltiples usan allowlists del
servidor; una opción agregada desde las herramientas del navegador se rechaza.
Los archivos sólo se convierten a `UploadedFile` y la política asociada se
obtiene con `uploadPolicy()` para entregarlos a `UploadVault`.

`Form::submit()` compara `_csrf` mediante `hash_equals` además de la protección
del middleware. Los errores generan `aria-describedby`, los archivos activan
`multipart/form-data`, y nombres, valores y etiquetas se escapan por defecto.

## Internacionalización tipada

`TranslationCatalog` define mensajes y el tipo exacto de cada parámetro. Las
claves, placeholders y esquemas se validan al construir el catálogo; los idiomas
adicionales no pueden inventar claves ni cambiar el contrato del idioma base.

```php
$es = (new TranslationCatalog('es-MX'))
    ->message('records.count', '{count} registros', ['count' => 'non-negative-int']);

$en = (new TranslationCatalog('en-US'))
    ->message('records.count', '{count} records', ['count' => 'non-negative-int']);

$translator = (new Translator($es))->add($en)->forLocale('en-US');
$text = $translator->text('records.count', ['count' => 5]);
$html = $translator->html('citizen.greeting', ['name' => $userName]);
```

`html()` escapa toda la frase después de interpolar; los catálogos no contienen
HTML confiable. El fallback sólo consulta el catálogo base y una clave ausente
falla explícitamente. No se usan archivos JSON ni se construyen rutas desde el
locale.

`LocaleNegotiator` procesa `Accept-Language` con límite de longitud, valores `q`
y una allowlist cerrada. Una etiqueta regional puede caer al idioma permitido,
pero texto con traversal, caracteres inesperados o tamaño excesivo obtiene el
locale predeterminado.

`WebTranslations` incluye `es-MX` y `en-US`. `Layout`, `DataTable`, `Pagination`,
`Form`, `ErrorPage`, `Response::error()` y `Router` aceptan el mismo `Translator`;
`Page` emite el atributo `lang` correspondiente.

## Auditoría WCAG 2.2 AA

`AccessibilityAudit` analiza el HTML seguro generado por JAS sin depender de la
extensión DOM. El reporte contiene código estable, criterio WCAG, severidad,
mensaje y elemento afectado:

```php
$report = (new AccessibilityAudit())->audit($page->render()->value());
$report->assertAutomatedPass();

$summary = $report->summary();
// standard, target, automated_pass, errors, warnings, manual_checks_required
```

La auditoría automática comprueba, entre otros controles:

- idioma, título, doctype, head/body, viewport y landmark principal;
- un único `h1`, orden de encabezados y texto no vacío;
- `alt` de imágenes y nombre accesible de enlaces y botones;
- labels, descripciones de error y referencias ARIA existentes;
- IDs únicos, destinos de enlaces internos y prohibición de tabindex positivo;
- caption y scope de encabezados en tablas;
- que el viewport no desactive zoom y use el ancho del dispositivo.

`passesAutomatedChecks()` no equivale a certificación WCAG. `isComplete()` exige
además una referencia de evidencia para cada revisión manual: contraste, reflow,
teclado, foco, objetivos táctiles, autenticación accesible y lector de pantalla.
Una cadena vacía no cuenta como evidencia. La evaluación debe conservar quién,
cuándo, navegador, tecnología asistiva, resultado y vínculo al artefacto de
prueba en el sistema institucional correspondiente.

La aplicación de referencia usa `Layout` y su estructura automatizable pasa en
`tests/test_jas_accessibility.php`. La misma prueba contiene un documento
adversarial para demostrar que los hallazgos no son meramente declarativos.

El HTML se construye con componentes y se escapa por defecto. `SafeHtml` representa
marcado producido por JAS; los valores de usuario siempre se pasan como hijos o
atributos normales para que sean escapados.

Consulte `examples/social_network.php` para ver una aplicación mínima completa.
