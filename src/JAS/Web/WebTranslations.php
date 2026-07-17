<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

final class WebTranslations
{
    public static function translator(string $locale = 'es-MX'): Translator
    {
        return (new Translator(self::spanish()))->add(self::english())->forLocale($locale);
    }

    private static function spanish(): TranslationCatalog
    {
        $catalog = (new TranslationCatalog('es-MX'))
            ->message('layout.skip', 'Saltar al contenido principal')
            ->message('layout.navigation', 'Navegación principal')
            ->message('table.empty', 'No hay registros disponibles.')
            ->message('pagination.summary', 'Página {current} de {total}', ['current' => 'positive-int', 'total' => 'positive-int'])
            ->message('pagination.previous', 'Anterior')
            ->message('pagination.next', 'Siguiente')
            ->message('pagination.page', 'Página {page}', ['page' => 'positive-int'])
            ->message('form.submit', 'Enviar')
            ->message('form.error.required', 'Este campo es obligatorio.')
            ->message('form.error.invalid_csrf', 'La sesión del formulario venció. Recarga la página.')
            ->message('form.error.invalid_type', 'El tipo de dato no es válido.')
            ->message('form.error.invalid_value', 'El valor proporcionado no está permitido.')
            ->message('form.error.unknown_field', 'El formulario contiene un campo no permitido.')
            ->message('form.error.unknown_file', 'El formulario contiene un archivo no permitido.')
            ->message('form.error.contract_mismatch', 'Los datos no cumplen el contrato del formulario.')
            ->message('error.reference', 'Referencia: {request_id}', ['request_id' => 'string'])
            ->message('error.home', 'Volver al inicio');
        foreach (self::errors('es-MX') as $status => [$title, $message]) {
            $catalog->message('error.' . $status . '.title', $title)->message('error.' . $status . '.message', $message);
        }
        return $catalog;
    }

    private static function english(): TranslationCatalog
    {
        $catalog = (new TranslationCatalog('en-US'))
            ->message('layout.skip', 'Skip to main content')
            ->message('layout.navigation', 'Main navigation')
            ->message('table.empty', 'No records are available.')
            ->message('pagination.summary', 'Page {current} of {total}', ['current' => 'positive-int', 'total' => 'positive-int'])
            ->message('pagination.previous', 'Previous')
            ->message('pagination.next', 'Next')
            ->message('pagination.page', 'Page {page}', ['page' => 'positive-int'])
            ->message('form.submit', 'Submit')
            ->message('form.error.required', 'This field is required.')
            ->message('form.error.invalid_csrf', 'The form session expired. Reload the page.')
            ->message('form.error.invalid_type', 'The data type is invalid.')
            ->message('form.error.invalid_value', 'The provided value is not allowed.')
            ->message('form.error.unknown_field', 'The form contains a field that is not allowed.')
            ->message('form.error.unknown_file', 'The form contains a file that is not allowed.')
            ->message('form.error.contract_mismatch', 'The data does not satisfy the form contract.')
            ->message('error.reference', 'Reference: {request_id}', ['request_id' => 'string'])
            ->message('error.home', 'Return home');
        foreach (self::errors('en-US') as $status => [$title, $message]) {
            $catalog->message('error.' . $status . '.title', $title)->message('error.' . $status . '.message', $message);
        }
        return $catalog;
    }

    /** @return array<int,array{string,string}> */
    private static function errors(string $locale): array
    {
        if ($locale === 'en-US') return [
            400 => ['Invalid request', 'The request could not be processed.'],
            401 => ['Authentication required', 'Sign in to continue.'],
            403 => ['Access denied', 'You are not authorized to perform this operation.'],
            404 => ['Page not found', 'The requested resource does not exist or is no longer available.'],
            409 => ['Conflict', 'The operation conflicts with the current state.'],
            422 => ['Invalid data', 'Review the provided information and try again.'],
            429 => ['Too many requests', 'Wait before trying again.'],
            500 => ['Internal error', 'The operation could not be completed safely.'],
            503 => ['Service unavailable', 'The service is temporarily unavailable.'],
        ];
        return [
            400 => ['Solicitud incorrecta', 'La solicitud no pudo procesarse.'],
            401 => ['Autenticación requerida', 'Inicia sesión para continuar.'],
            403 => ['Acceso denegado', 'No tienes autorización para realizar esta operación.'],
            404 => ['Página no encontrada', 'El recurso solicitado no existe o ya no está disponible.'],
            409 => ['Conflicto', 'La operación entra en conflicto con el estado actual.'],
            422 => ['Datos no válidos', 'Revisa la información proporcionada e inténtalo nuevamente.'],
            429 => ['Demasiadas solicitudes', 'Espera un momento antes de intentarlo nuevamente.'],
            500 => ['Error interno', 'No fue posible completar la operación de forma segura.'],
            503 => ['Servicio no disponible', 'El servicio no está disponible temporalmente.'],
        ];
    }
}
