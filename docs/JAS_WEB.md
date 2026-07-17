# JAS Web

JAS Web conecta rutas HTTP con acciones gobernadas. No existen controladores que
puedan omitir tipos, capacidades, WAL o auditoría.

```php
$router = (new Router($runtime))
    ->middleware(new SecurityHeadersMiddleware())
    ->middleware(new CsrfMiddleware(fn() => $_SESSION['csrf']))
    ->middleware(new RateLimitMiddleware($store, $identityResolver));
```

El HTML se construye con componentes y se escapa por defecto. `SafeHtml` representa
marcado producido por JAS; los valores de usuario siempre se pasan como hijos o
atributos normales para que sean escapados.

Consulte `examples/social_network.php` para ver una aplicación mínima completa.
