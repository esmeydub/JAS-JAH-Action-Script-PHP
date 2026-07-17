<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Type\TypeRegistry;
use Jah\JAS\Web\DataTable;
use Jah\JAS\Web\Form;
use Jah\JAS\Web\Layout;
use Jah\JAS\Web\LocaleNegotiator;
use Jah\JAS\Web\Response;
use Jah\JAS\Web\TranslationCatalog;
use Jah\JAS\Web\Translator;
use Jah\JAS\Web\WebTranslations;

$throws = static function (callable $operation, string $expected): void {
    try { $operation(); } catch (Throwable $exception) { if ($exception->getMessage() === $expected) return; throw $exception; }
    throw new RuntimeException("Expected {$expected}");
};

$spanish = (new TranslationCatalog('es-MX'))
    ->message('citizen.greeting', 'Hola {name}', ['name' => 'non-empty-string'])
    ->message('records.count', '{count} registros', ['count' => 'non-negative-int'])
    ->message('fallback.only', 'Texto de respaldo');
$english = (new TranslationCatalog('en-US'))
    ->message('citizen.greeting', 'Hello {name}', ['name' => 'non-empty-string'])
    ->message('records.count', '{count} records', ['count' => 'non-negative-int']);
$translator = (new Translator($spanish))->add($english)->forLocale('en-US');
if ($translator->text('citizen.greeting', ['name' => 'Ana']) !== 'Hello Ana') throw new RuntimeException('translation_render_failed');
if ($translator->text('fallback.only') !== 'Texto de respaldo') throw new RuntimeException('translation_fallback_failed');
$safe = $translator->html('citizen.greeting', ['name' => '<script>alert(1)</script>'])->value();
if (!str_contains($safe, '&lt;script&gt;') || str_contains($safe, '<script>')) throw new RuntimeException('translation_html_escape_failed');
$throws(fn() => $translator->text('records.count', ['count' => '5']), 'translation_argument_type_invalid');
$throws(fn() => $translator->text('records.count', ['total' => 5]), 'translation_arguments_mismatch');
$throws(fn() => $translator->text('missing.key'), 'translation_missing');
$throws(
    fn() => (new Translator($spanish))->add((new TranslationCatalog('fr-FR'))->message('records.count', '{count} éléments', ['count' => 'int'])),
    'translation_schema_mismatch',
);
$throws(fn() => (new TranslationCatalog('es-MX'))->message('bad.template', 'Hola {name}', []), 'translation_placeholders_mismatch');
$throws(fn() => (new TranslationCatalog('../etc'))->message('bad.locale', 'Bad'), 'translation_locale_invalid');

$negotiator = new LocaleNegotiator(['es-MX', 'en-US'], 'es-MX');
if ($negotiator->negotiate('fr-FR;q=0.9, en-US;q=0.8') !== 'en-US') throw new RuntimeException('locale_quality_negotiation_failed');
if ($negotiator->negotiate('en-GB') !== 'en-US') throw new RuntimeException('locale_language_fallback_failed');
if ($negotiator->negotiate('../../etc/passwd') !== 'es-MX') throw new RuntimeException('locale_path_injection_accepted');
if ($negotiator->negotiate(str_repeat('A', 1_025)) !== 'es-MX') throw new RuntimeException('locale_oversize_header_accepted');

$webEnglish = WebTranslations::translator('en-US');
$error = Response::error(404, 'REQUEST-I18N-1', translator: $webEnglish);
if (!str_contains($error->body, '<html lang="en-US">') || !str_contains($error->body, 'Page not found') || !str_contains($error->body, 'Reference: REQUEST-I18N-1')) {
    throw new RuntimeException('localized_error_page_failed');
}
$layout = (new Layout(translator: $webEnglish))->slot('main', 'Content')->render()->value();
if (!str_contains($layout, 'Skip to main content')) throw new RuntimeException('localized_layout_failed');
$table = (new DataTable('Records', ['id' => 'ID'], [], translator: $webEnglish))->render()->value();
if (!str_contains($table, 'No records are available.')) throw new RuntimeException('localized_table_failed');
$formTypes = (new TypeRegistry())->define('LocalizedForm', ['id' => 'identifier']);
$form = new Form($formTypes, 'LocalizedForm', '/submit', str_repeat('i', 64), translator: $webEnglish);
if (!str_contains($form->render()->value(), '>Submit</button>')) throw new RuntimeException('localized_form_failed');

echo "JAS I18N: PASS\n";
