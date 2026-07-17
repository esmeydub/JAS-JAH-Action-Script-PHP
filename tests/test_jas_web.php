<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Web\Component;
use Jah\JAS\Web\Html;
use Jah\JAS\Web\Page;
use Jah\JAS\Web\SafeHtml;
use Jah\JAS\Web\Request;
use Jah\JAS\Web\Response;
use Jah\JAS\Web\Router;
use Jah\JAS\Web\CsrfMiddleware;
use Jah\JAS\Web\SecurityHeadersMiddleware;
use Jah\JAS\Web\RateLimitMiddleware;
use Jah\JAS\Web\RateLimitStore;
use Jah\JAS\Web\AuthMiddleware;
use Jah\JAS\Security\AuthStore;
use Jah\JAS\Security\RolePolicy;
use Jah\JAS\Web\Form;
use Jah\JAS\Web\TraceMiddleware;
use Jah\JAS\Web\Middleware;
use Jah\JAS\Observability\JahLogger;
use Jah\JAS\Observability\HealthRegistry;
use Jah\JAS\Jas;
use Jah\JAS\Security\KeyRing;
use Jah\JAS\Web\SecureCookieJar;

$throws = static function (callable $operation, string $expected): void {
    try { $operation(); } catch (Throwable $e) { if ($e->getMessage() === $expected) return; throw $e; }
    throw new RuntimeException("Expected {$expected}");
};

$component = new class implements Component {
    public function render(): SafeHtml
    {
        return Html::element('main', ['class' => 'profile'],
            Html::element('h1', [], 'Perfil <administrador>'),
            Html::element('a', ['href' => '/usuarios?id=1&active=1'], 'Consultar')
        );
    }
};
$page = (new Page('Portal ciudadano', $component))->render()->value();
if (!str_contains($page, 'Perfil &lt;administrador&gt;')) throw new RuntimeException('html_text_not_escaped');
if (!str_contains($page, 'id=1&amp;active=1')) throw new RuntimeException('html_attribute_not_escaped');
if (!str_starts_with($page, '<!doctype html>')) throw new RuntimeException('html_document_invalid');

$throws(fn() => Html::element('script', [], 'alert(1)'), 'html_tag_not_allowed');
$throws(fn() => Html::element('a', ['onclick' => 'steal()'], 'bad'), 'html_attribute_not_allowed');
$throws(fn() => Html::element('a', ['href' => 'javascript:alert(1)'], 'bad'), 'html_url_scheme_forbidden');
$throws(fn() => Html::element('input', [], 'child'), 'html_void_element_children');

$webApp = Jas::application('Portal Web Seguro')
    ->type('ConsultaPerfil', ['id' => 'identifier'])
    ->type('PerfilEncontrado', ['id' => 'identifier', 'nombre' => 'non-empty-string'])
    ->domain('Perfiles', 'perfil');
$webApp->action('Perfiles', 'perfil.consultar')
    ->input('ConsultaPerfil')->output('PerfilEncontrado')->requires('perfiles.read')->audit();
$webRuntime = $webApp->runtime(['web' => ['perfiles.read']], 'web', sys_get_temp_dir() . '/jas_web_' . bin2hex(random_bytes(4)));
$webRuntime->handle('perfil.consultar', static fn(array $input): array => ['id' => $input['id'], 'nombre' => '<Ciudadano>']);
$router = (new Router($webRuntime))->route('GET', '/perfil', 'perfil.consultar', static fn(array $profile): Response => Response::html(
    Html::element('h1', [], $profile['nombre'])
));
$response = $router->dispatch(new Request('GET', '/perfil', ['id' => 'USER-1'], requestId: 'web-1'));
if ($response->status !== 200 || !str_contains($response->body, '&lt;Ciudadano&gt;')) throw new RuntimeException('secure_route_failed');
if ($router->dispatch(new Request('GET', '/missing'))->status !== 404) throw new RuntimeException('route_not_found_failed');
if ($router->dispatch(new Request('GET', '/perfil', ['id' => 'bad id']))->status !== 422) throw new RuntimeException('route_type_validation_failed');
$dynamicRouter = (new Router($webRuntime))->route(
    'GET', '/perfiles/{id}', 'perfil.consultar',
    static fn(array $profile, array $result, Request $request): Response => new Response($profile['id'] . ':' . $request->input['id']),
    'perfiles.show'
);
if ($dynamicRouter->url('perfiles.show', ['id' => 'USER-1'], ['tab' => 'security']) !== '/perfiles/USER-1?tab=security') throw new RuntimeException('named_route_url_failed');
$dynamic = $dynamicRouter->dispatch(new Request('GET', '/perfiles/USER-1'));
if ($dynamic->status !== 200 || $dynamic->body !== 'USER-1:USER-1') throw new RuntimeException('dynamic_route_failed');
if ($dynamicRouter->dispatch(new Request('GET', '/perfiles/USER-1', ['id' => 'USER-2']))->status !== 400) throw new RuntimeException('route_parameter_conflict_accepted');

$groupGuard = new class implements Middleware {
    public int $calls = 0;
    public function process(Request $request, callable $next): Response
    {
        $this->calls++;
        $response = $next($request);
        return $response->withHeaders(['X-JAS-Group' => 'applied']);
    }
};
$routeGuard = new class implements Middleware {
    public int $calls = 0;
    public function process(Request $request, callable $next): Response
    {
        $this->calls++;
        if (($request->attributes['route_template'] ?? null) !== '/api/v1/perfiles/{id}') return new Response('route_metadata_missing', 500);
        $response = $next($request);
        return $response->withHeaders(['X-JAS-Route' => 'applied']);
    }
};
$groupedRouter = new Router($webRuntime);
$groupedRouter->group('/api', [$groupGuard], static function (Router $api) use ($routeGuard): void {
    $api->group('/v1', [], static function (Router $v1) use ($routeGuard): void {
        $v1->route(
            'GET', '/perfiles/{id}', 'perfil.consultar',
            static fn(array $profile, array $result, Request $request): Response => new Response($profile['id'] . ':' . $request->attributes['route_name']),
            'api.perfiles.show',
            [$routeGuard]
        );
    });
});
$groupedRouter->route('GET', '/perfil', 'perfil.consultar', static fn(array $profile): Response => new Response($profile['id']), 'perfil.ungrouped');
if ($groupedRouter->url('api.perfiles.show', ['id' => 'USER-1']) !== '/api/v1/perfiles/USER-1') throw new RuntimeException('grouped_route_url_failed');
$grouped = $groupedRouter->dispatch(new Request('GET', '/api/v1/perfiles/USER-1'));
if ($grouped->status !== 200 || $grouped->body !== 'USER-1:api.perfiles.show') throw new RuntimeException('grouped_route_failed');
if (($grouped->headers['X-JAS-Group'] ?? null) !== 'applied' || ($grouped->headers['X-JAS-Route'] ?? null) !== 'applied') throw new RuntimeException('grouped_middleware_failed');
if ($groupGuard->calls !== 1 || $routeGuard->calls !== 1) throw new RuntimeException('route_middleware_call_count_invalid');
if (isset($groupedRouter->dispatch(new Request('GET', '/perfil', ['id' => 'USER-1']))->headers['X-JAS-Group'])) throw new RuntimeException('group_middleware_leaked');
$throws(fn() => $groupedRouter->group('/bad/{tenant}', [], static function (): void {}), 'http_group_prefix_invalid');
$throws(fn() => $groupedRouter->route('GET', '/invalid', 'perfil.consultar', static fn(): Response => new Response('bad'), middleware: ['invalid']), 'http_middleware_invalid');

$cookieJar = (new SecureCookieJar(new KeyRing(['web-2026' => random_bytes(32)], 'web-2026')))
    ->define('identity', 'identifier', 900)
    ->define('page_size', 'positive-int', 3_600, 'Lax');
$identityCookie = $cookieJar->issue('identity', 'USER-1', 1_800_000_000);
if (!str_contains($identityCookie->header(), 'Path=/; Secure; HttpOnly; SameSite=Strict')) throw new RuntimeException('secure_cookie_attributes_missing');
if ($cookieJar->read('identity', [$identityCookie->name => $identityCookie->value], 1_800_000_100) !== 'USER-1') throw new RuntimeException('typed_cookie_roundtrip_failed');
$cookieResponse = (new Response('cookies'))
    ->withCookie($identityCookie)
    ->withCookie($cookieJar->issue('page_size', 25, 1_800_000_000));
if (!is_array($cookieResponse->headers['Set-Cookie'] ?? null) || count($cookieResponse->headers['Set-Cookie']) !== 2) throw new RuntimeException('multiple_set_cookie_failed');
$tamperedCookie = substr($identityCookie->value, 0, -1) . ($identityCookie->value[-1] === 'A' ? 'B' : 'A');
$throws(fn() => $cookieJar->read('identity', [$identityCookie->name => $tamperedCookie], 1_800_000_100), 'secure_cookie_invalid');
$throws(fn() => $cookieJar->read('identity', [$identityCookie->name => $identityCookie->value], 1_800_001_000), 'secure_cookie_expired');
$throws(fn() => $cookieJar->issue('page_size', '25'), 'cookie_value_type_invalid');
if (!str_contains($cookieJar->forget('identity', 1_800_000_000)->header(), 'Max-Age=0')) throw new RuntimeException('secure_cookie_forget_failed');

$csrf = str_repeat('c', 64);
$secureRouter = (new Router($webRuntime))
    ->middleware(new SecurityHeadersMiddleware())
    ->middleware(new CsrfMiddleware(static fn(): string => $csrf))
    ->route('POST', '/perfil', 'perfil.consultar', static fn(array $profile): Response => new Response($profile['id']));
if ($secureRouter->dispatch(new Request('POST', '/perfil', ['id' => 'USER-1']))->status !== 403) throw new RuntimeException('csrf_missing_accepted');
$secured = $secureRouter->dispatch(new Request('POST', '/perfil', ['id' => 'USER-1', '_csrf' => $csrf], requestId: 'web-post-1'));
if ($secured->status !== 200 || ($secured->headers['X-Frame-Options'] ?? null) !== 'DENY') throw new RuntimeException('security_middleware_failed');

$streamRouter = (new Router($webRuntime))
    ->middleware(new SecurityHeadersMiddleware())
    ->route('GET', '/perfil-stream', 'perfil.consultar', static fn(array $profile): Response => Response::stream(
        static function (callable $write) use ($profile): void { $write($profile['id']); },
        'text/plain',
    ));
$streamedResponse = $streamRouter->dispatch(new Request('GET', '/perfil-stream', ['id' => 'USER-1']));
$streamedBody = '';
$streamedResponse->emit(static function (string $chunk) use (&$streamedBody): void { $streamedBody .= $chunk; });
if (!$streamedResponse->isStreamed() || $streamedBody !== 'USER-1' || ($streamedResponse->headers['X-Frame-Options'] ?? null) !== 'DENY') {
    throw new RuntimeException('streaming_middleware_preservation_failed');
}

$limitedRouter = (new Router($webRuntime))
    ->middleware(new RateLimitMiddleware(
        new RateLimitStore(sys_get_temp_dir() . '/jas_rate_' . bin2hex(random_bytes(4))),
        static fn(Request $request): string => (string) ($request->headers['x-user-id'] ?? ''),
        2,
        60
    ))
    ->route('GET', '/perfil', 'perfil.consultar', static fn(array $profile): Response => new Response($profile['id']));
$limitedRequest = new Request('GET', '/perfil', ['id' => 'USER-1'], ['x-user-id' => 'USER-1']);
if ($limitedRouter->dispatch($limitedRequest)->status !== 200) throw new RuntimeException('rate_limit_first_failed');
if ($limitedRouter->dispatch($limitedRequest)->status !== 200) throw new RuntimeException('rate_limit_second_failed');
$limited = $limitedRouter->dispatch($limitedRequest);
if ($limited->status !== 429 || !isset($limited->headers['Retry-After'])) throw new RuntimeException('rate_limit_not_enforced');

$auth = new AuthStore(sys_get_temp_dir() . '/jas_auth_' . bin2hex(random_bytes(4)));
$auth->createUser('USER-ADMIN', 'administrador', 'Clave-Segura-2026!', ['admin']);
$auth->createUser('USER-READER', 'consulta', 'Otra-Clave-Segura-2026!', ['reader']);
$adminToken = $auth->login('administrador', 'Clave-Segura-2026!');
$readerToken = $auth->login('consulta', 'Otra-Clave-Segura-2026!');
$roles = new RolePolicy(['admin' => ['perfiles.*'], 'reader' => ['perfiles.read']]);
$authRouter = (new Router($webRuntime))
    ->middleware(new AuthMiddleware($auth, $roles, ['GET /perfil' => 'perfiles.write']))
    ->route('GET', '/perfil', 'perfil.consultar', static fn(array $profile, array $result, Request $request): Response => new Response((string) $request->attributes['identity']['username']));
if ($authRouter->dispatch(new Request('GET', '/perfil', ['id' => 'USER-1']))->status !== 401) throw new RuntimeException('anonymous_access_accepted');
if ($authRouter->dispatch(new Request('GET', '/perfil', ['id' => 'USER-1'], ['authorization' => 'Bearer ' . $readerToken]))->status !== 403) throw new RuntimeException('role_permission_not_enforced');
$authorized = $authRouter->dispatch(new Request('GET', '/perfil', ['id' => 'USER-1'], ['authorization' => 'Bearer ' . $adminToken]));
if ($authorized->status !== 200 || $authorized->body !== 'administrador') throw new RuntimeException('authorized_identity_missing');
$auth->logout($adminToken);
if ($authRouter->dispatch(new Request('GET', '/perfil', ['id' => 'USER-1'], ['authorization' => 'Bearer ' . $adminToken]))->status !== 401) throw new RuntimeException('revoked_session_accepted');
$passwordToken = $auth->login('consulta', 'Otra-Clave-Segura-2026!');
$auth->changePassword($passwordToken, 'Otra-Clave-Segura-2026!', 'Clave-Nueva-Segura-2026!');
if ($auth->identity($passwordToken) !== null) throw new RuntimeException('password_change_did_not_revoke_sessions');
$newToken = $auth->login('consulta', 'Clave-Nueva-Segura-2026!');
$auth->setUserActive('USER-READER', false);
if ($auth->identity($newToken) !== null) throw new RuntimeException('disabled_user_session_active');

$lockAuth = new AuthStore(sys_get_temp_dir() . '/jas_auth_lock_' . bin2hex(random_bytes(4)));
$lockAuth->createUser('USER-LOCK', 'bloqueado', 'Clave-Correcta-2026!', ['reader']);
for ($attempt = 0; $attempt < 5; $attempt++) {
    try { $lockAuth->login('bloqueado', 'Clave-Incorrecta!'); } catch (RuntimeException) {}
}
$throws(fn() => $lockAuth->login('bloqueado', 'Clave-Correcta-2026!'), 'auth_login_locked');

$formTypes = (new \Jah\JAS\Type\TypeRegistry())->define('AltaCiudadano', [
    'id' => 'identifier', 'nombre' => 'non-empty-string', 'edad' => 'positive-int', 'telefono?' => 'string',
]);
$form = new Form($formTypes, 'AltaCiudadano', '/ciudadanos', str_repeat('f', 64), ['id' => 'Folio', 'nombre' => 'Nombre completo']);
$validForm = $form->submit(['id' => 'CIUDADANO-1', 'nombre' => 'Ana', 'edad' => '35', '_csrf' => str_repeat('f', 64)]);
if (!$validForm['valid'] || $validForm['data']['edad'] !== 35) throw new RuntimeException('typed_form_coercion_failed');
$invalidForm = $form->submit(['id' => 'id con espacios', 'nombre' => '', 'edad' => '-2', 'hidden' => 'x']);
if ($invalidForm['valid'] || !isset($invalidForm['errors']['hidden'])) throw new RuntimeException('typed_form_validation_failed');
$formHtml = $form->render()->value();
if (!str_contains($formHtml, 'aria-invalid="true"') || !str_contains($formHtml, 'name="_csrf"')) throw new RuntimeException('accessible_form_render_failed');

$observationPath = sys_get_temp_dir() . '/jas_observe_' . bin2hex(random_bytes(4));
$logger = new JahLogger($observationPath . '/app.jahl');
$traceRouter = (new Router($webRuntime))->middleware(new TraceMiddleware($logger))
    ->route('GET', '/perfil', 'perfil.consultar', static fn(array $profile): Response => new Response($profile['id']));
$traced = $traceRouter->dispatch(new Request('GET', '/perfil', ['id' => 'USER-1']));
if (!isset($traced->headers['X-JAS-Request-ID']) || count($logger->records()) !== 1) throw new RuntimeException('request_trace_failed');
$health = (new HealthRegistry())->check('datacore.ready', static fn(): bool => true)->check('queue.ready', static fn(): array => ['ok' => true])->run();
if (!$health['ok'] || count($health['checks']) !== 2) throw new RuntimeException('health_registry_failed');

echo "JAS WEB: PASS\n";
