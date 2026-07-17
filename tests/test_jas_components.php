<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Jas;
use Jah\JAS\Web\DataTable;
use Jah\JAS\Web\ErrorPage;
use Jah\JAS\Web\Html;
use Jah\JAS\Web\Layout;
use Jah\JAS\Web\Page;
use Jah\JAS\Web\Pagination;
use Jah\JAS\Web\Request;
use Jah\JAS\Web\Response;
use Jah\JAS\Web\Router;

$throws = static function (callable $operation, string $expected): void {
    try { $operation(); } catch (Throwable $exception) { if ($exception->getMessage() === $expected) return; throw $exception; }
    throw new RuntimeException("Expected {$expected}");
};

$layout = (new Layout('Navegación ciudadana'))
    ->slot('header', Html::element('h1', [], 'Portal ciudadano'))
    ->slot('navigation', Html::element('a', ['href' => '/tramites'], 'Trámites'))
    ->slot('main', 'Contenido <privado>')
    ->slot('footer', 'Institución pública');
$layoutHtml = (new Page('Portal', $layout))->render()->value();
if (!str_contains($layoutHtml, 'href="#jas-main"') || !str_contains($layoutHtml, '<main id="jas-main" tabindex="-1">')) throw new RuntimeException('accessible_layout_landmarks_failed');
if (!str_contains($layoutHtml, 'Contenido &lt;privado&gt;') || str_contains($layoutHtml, 'Contenido <privado>')) throw new RuntimeException('layout_xss_escape_failed');
if (!str_contains($layoutHtml, '<nav aria-label="Navegación ciudadana">')) throw new RuntimeException('layout_navigation_label_failed');
$throws(fn() => (new Layout())->render(), 'layout_main_required');
$throws(fn() => (new Layout())->slot('main', 'a')->slot('main', 'b'), 'layout_slot_duplicated');
$throws(fn() => (new Layout())->slot('scripts', 'bad'), 'layout_slot_invalid');

$table = new DataTable(
    'Personas registradas',
    ['name' => 'Nombre', 'role' => 'Rol'],
    [
        ['name' => '<Admin>', 'role' => 'Administrador'],
        ['name' => 'Ana', 'role' => Html::element('strong', [], 'Consulta')],
    ],
    'name',
);
$tableHtml = $table->render()->value();
if (!str_contains($tableHtml, '<caption>Personas registradas</caption>') || !str_contains($tableHtml, '<th scope="col">Nombre</th>')) throw new RuntimeException('accessible_table_header_failed');
if (!str_contains($tableHtml, '<th scope="row">&lt;Admin&gt;</th>') || str_contains($tableHtml, '<Admin>')) throw new RuntimeException('table_xss_escape_failed');
if (!str_contains($tableHtml, 'role="region"') || !str_contains($tableHtml, 'tabindex="0"')) throw new RuntimeException('responsive_table_region_failed');
$emptyTable = (new DataTable('Sin resultados', ['id' => 'Folio'], []))->render()->value();
if (!str_contains($emptyTable, 'colspan="1"') || !str_contains($emptyTable, 'No hay registros disponibles.')) throw new RuntimeException('empty_table_failed');
$throws(fn() => new DataTable('Bad', ['id' => 'Folio'], [['other' => 'X']]), 'table_row_contract_invalid');

$application = Jas::application('Component Test')
    ->type('PageInput', ['id' => 'identifier'])
    ->type('PageOutput', ['id' => 'identifier'])
    ->domain('Pages', 'page');
$application->action('Pages', 'page.show')->input('PageInput')->output('PageOutput')->requires('pages.read')->audit();
$runtime = $application->runtime(['web' => ['pages.read']], 'web', sys_get_temp_dir() . '/jas_components_' . bin2hex(random_bytes(4)));
$runtime->handle('page.show', static fn(array $input): array => $input);
$router = (new Router($runtime))->route('GET', '/records/{id}', 'page.show', static fn(array $result): Response => new Response($result['id']), 'records.index');
$pagination = new Pagination($router, 'records.index', 5, 200, 10, ['id' => 'CITIZENS'], ['filter' => '<active>', 'page' => 99]);
$paginationHtml = $pagination->render()->value();
if (!str_contains($paginationHtml, 'aria-current="page"') || !str_contains($paginationHtml, 'rel="prev"') || !str_contains($paginationHtml, 'rel="next"')) throw new RuntimeException('pagination_accessibility_failed');
if (!str_contains($paginationHtml, 'filter=%3Cactive%3E&amp;page=5') || str_contains($paginationHtml, 'page=99')) throw new RuntimeException('pagination_url_failed');
$throws(fn() => new Pagination($router, 'records.index', 21, 200, 10, ['id' => 'CITIZENS']), 'pagination_page_out_of_range');

$error = Response::error(500, 'REQ-<private>');
if ($error->status !== 500 || $error->contentType !== 'text/html; charset=utf-8') throw new RuntimeException('error_response_failed');
if (!str_contains($error->body, 'REQ-&lt;private&gt;') || str_contains($error->body, 'REQ-<private>')) throw new RuntimeException('error_request_id_xss_failed');
$throws(fn() => new ErrorPage(418), 'error_page_status_invalid');
$failureRouter = (new Router($runtime))->route('GET', '/failure', 'page.show', static function (): Response {
    throw new RuntimeException('DATABASE_PASSWORD=secret');
});
$failure = $failureRouter->dispatch(new Request('GET', '/failure', ['id' => 'CITIZENS'], requestId: 'FAILURE-REQ-1'));
if ($failure->status !== 500 || str_contains($failure->body, 'DATABASE_PASSWORD') || !str_contains($failure->body, 'No fue posible completar')) {
    throw new RuntimeException('internal_error_leaked');
}

echo "JAS COMPONENTS: PASS\n";
