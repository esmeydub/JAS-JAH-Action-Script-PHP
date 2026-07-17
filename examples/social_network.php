<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Jas;
use Jah\JAS\Web\Html;
use Jah\JAS\Web\Page;
use Jah\JAS\Web\Request;
use Jah\JAS\Web\Response;
use Jah\JAS\Web\Router;
use Jah\JAS\Web\SecurityHeadersMiddleware;

$app = Jas::application('Red Social JAS')
    ->type('ConsultaPublicacion', ['id' => 'identifier'])
    ->type('PublicacionEncontrada', ['id' => 'identifier', 'autor_id' => 'identifier', 'contenido' => 'non-empty-string'])
    ->domain('Identidad', 'identidad')
    ->domain('Publicaciones', 'publicacion', ['Identidad']);

$app->action('Publicaciones', 'publicacion.consultar')
    ->input('ConsultaPublicacion')
    ->output('PublicacionEncontrada')
    ->requires('publicaciones.read')
    ->audit();

$runtime = $app->runtime(
    ['web' => ['publicaciones.read']],
    'web',
    dirname(__DIR__) . '/runtime/example-social'
);

$runtime->handle('publicacion.consultar', static fn(array $input): array => [
    'id' => $input['id'],
    'autor_id' => 'USER-1',
    'contenido' => 'Aplicación organizada con Jah ActionScript',
]);

$router = (new Router($runtime))
    ->middleware(new SecurityHeadersMiddleware())
    ->route('GET', '/publicacion', 'publicacion.consultar', static function (array $post): Response {
        return Response::html(new Page('Publicación JAS', Html::element('main', [],
            Html::element('h1', [], 'Publicación'),
            Html::element('p', [], $post['contenido']),
            Html::element('small', [], 'Autor: ' . $post['autor_id'])
        )));
    });

$router->dispatch(Request::fromGlobals())->send();
