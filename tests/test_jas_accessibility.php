<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Type\TypeRegistry;
use Jah\JAS\Web\AccessibilityAudit;
use Jah\JAS\Web\DataTable;
use Jah\JAS\Web\Form;
use Jah\JAS\Web\Html;
use Jah\JAS\Web\Layout;
use Jah\JAS\Web\Page;

$audit = new AccessibilityAudit();
$types = (new TypeRegistry())->define('AccessibleSearch', ['query' => 'non-empty-string']);
$form = new Form($types, 'AccessibleSearch', '/search', str_repeat('a', 64), ['query' => 'Buscar']);
$table = new DataTable('Resultados de búsqueda', ['name' => 'Nombre', 'status' => 'Estado'], [
    ['name' => 'Solicitud 1', 'status' => 'Activa'],
], 'name');
$layout = (new Layout())
    ->slot('header', Html::element('h1', [], 'Portal de trámites'))
    ->slot('navigation', Html::element('a', ['href' => '/tramites'], 'Ver trámites'))
    ->slot('main', Html::fragment(
        Html::element('h2', [], 'Buscar solicitudes'),
        $form,
        Html::element('h2', [], 'Resultados'),
        $table,
    ));
$html = (new Page('Portal de trámites', $layout, 'es-MX'))->render()->value();
$report = $audit->audit($html);
$report->assertAutomatedPass();
$summary = $report->summary();
if (($summary['automated_pass'] ?? false) !== true || ($summary['errors'] ?? -1) !== 0 || ($summary['manual_checks_required'] ?? 0) < 1) {
    throw new RuntimeException('accessibility_report_summary_failed');
}
if ($report->isComplete([])) throw new RuntimeException('manual_accessibility_evidence_bypassed');
$evidence = [];
foreach ($report->manualChecks as $criterion => $_description) $evidence[$criterion] = 'review-2026:' . $criterion;
if (!$report->isComplete($evidence)) throw new RuntimeException('manual_accessibility_evidence_failed');

$invalid = <<<'HTML'
<!doctype html><html><head><title></title><meta name="viewport" content="width=device-width,maximum-scale=1"></head><body>
<h1>Title</h1><h3>Skipped</h3><a href="#missing"></a><img src="photo.png">
<input id="duplicate" tabindex="2" aria-describedby="unknown"><div id="duplicate"></div>
<table><tr><th>Header</th></tr></table>
</body></html>
HTML;
$failed = $audit->audit($invalid);
if ($failed->passesAutomatedChecks()) throw new RuntimeException('accessibility_invalid_document_accepted');
$codes = array_column($failed->findings, 'code');
foreach (['duplicate_id', 'document_language_missing', 'page_title_missing', 'main_landmark_invalid', 'heading_level_skipped', 'image_alt_missing', 'link_name_missing', 'fragment_target_missing', 'form_label_missing', 'positive_tabindex_forbidden', 'aria_reference_missing', 'table_caption_missing', 'table_header_scope_missing', 'viewport_zoom_disabled'] as $code) {
    if (!in_array($code, $codes, true)) throw new RuntimeException('accessibility_finding_missing:' . $code);
}
try { $failed->assertAutomatedPass(); } catch (RuntimeException $exception) {
    if (str_starts_with($exception->getMessage(), 'accessibility_audit_failed:')) echo "JAS ACCESSIBILITY NEGATIVE: PASS\n";
    else throw $exception;
}

echo "JAS ACCESSIBILITY: PASS\n";
