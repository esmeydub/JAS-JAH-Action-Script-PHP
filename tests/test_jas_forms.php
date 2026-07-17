<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Jah\JAS\Type\TypeRegistry;
use Jah\JAS\Web\Form;
use Jah\JAS\Web\FormControl;
use Jah\JAS\Web\UploadedFile;
use Jah\JAS\Web\UploadPolicy;

$throws = static function (callable $operation, string $expected): void {
    try { $operation(); } catch (Throwable $exception) { if ($exception->getMessage() === $expected) return; throw $exception; }
    throw new RuntimeException("Expected {$expected}");
};

$types = (new TypeRegistry())->define('GovernmentAppointment', [
    'birth_date' => 'date',
    'appointment_at' => 'datetime',
    'timezone' => 'timezone',
    'role' => 'identifier',
    'notifications' => 'string[]',
    'attachment' => UploadedFile::class,
]);
$uploadPolicy = new UploadPolicy('appointment-files', ['text/plain'], 1_024);
$csrf = str_repeat('s', 64);
$form = new Form(
    $types,
    'GovernmentAppointment',
    '/appointments',
    $csrf,
    labels: ['birth_date' => 'Fecha de nacimiento', 'appointment_at' => 'Fecha de cita'],
    controls: [
        'birth_date' => FormControl::date('1900-01-01', '2026-12-31'),
        'appointment_at' => FormControl::dateTime('timezone'),
        'timezone' => FormControl::timezone([
            'America/Mexico_City' => 'Ciudad de México <centro>',
            'UTC' => 'Tiempo universal',
        ]),
        'role' => FormControl::select(['citizen' => 'Ciudadanía', 'official' => 'Servidor público']),
        'notifications' => FormControl::select(['email' => 'Correo', 'sms' => 'Mensaje SMS'], true),
        'attachment' => FormControl::file($uploadPolicy),
    ],
);
$file = UploadedFile::fromBytes('evidence.txt', "verified evidence\n");
$valid = $form->submit([
    '_csrf' => $csrf,
    'birth_date' => '2000-02-29',
    'appointment_at' => '2026-01-15T10:30',
    'timezone' => 'America/Mexico_City',
    'role' => 'citizen',
    'notifications' => ['email', 'sms'],
], ['attachment' => $file]);
if (!$valid['valid']) throw new RuntimeException('advanced_form_rejected');
if (($valid['data']['appointment_at'] ?? null) !== '2026-01-15T16:30:00Z') throw new RuntimeException('form_datetime_utc_normalization_failed');
if (($valid['data']['birth_date'] ?? null) !== '2000-02-29' || ($valid['data']['timezone'] ?? null) !== 'America/Mexico_City') throw new RuntimeException('form_date_timezone_failed');
if (($valid['data']['notifications'] ?? null) !== ['email', 'sms'] || !(($valid['data']['attachment'] ?? null) instanceof UploadedFile)) throw new RuntimeException('form_select_or_file_failed');
if ($form->uploadPolicy('attachment') !== $uploadPolicy) throw new RuntimeException('form_upload_policy_failed');

$html = $form->render()->value();
if (!str_contains($html, 'enctype="multipart/form-data"') || !str_contains($html, 'type="file"') || !str_contains($html, 'accept="text/plain"')) throw new RuntimeException('multipart_form_render_failed');
if (!str_contains($html, 'type="date"') || !str_contains($html, 'type="datetime-local"') || !str_contains($html, '<select')) throw new RuntimeException('advanced_controls_render_failed');
if (!str_contains($html, 'name="notifications[]"') || !str_contains($html, 'multiple')) throw new RuntimeException('multiple_select_render_failed');
if (!str_contains($html, 'Ciudad de México &lt;centro&gt;') || str_contains($html, 'Ciudad de México <centro>')) throw new RuntimeException('select_label_xss_failed');

$invalid = $form->submit([
    '_csrf' => $csrf,
    'birth_date' => '2025-02-29',
    'appointment_at' => 'not-a-date',
    'timezone' => 'America/Unknown',
    'role' => 'super-admin',
    'notifications' => ['email', 'root'],
], []);
foreach (['birth_date', 'appointment_at', 'timezone', 'role', 'notifications', 'attachment'] as $field) {
    if (!isset($invalid['errors'][$field])) throw new RuntimeException('advanced_form_negative_case_missing:' . $field);
}
$invalidHtml = $form->render()->value();
if (!str_contains($invalidHtml, 'aria-describedby="jas-field-birth_date-error"')) throw new RuntimeException('form_error_description_failed');

$wrongCsrf = $form->submit([
    '_csrf' => str_repeat('x', 64),
    'birth_date' => '2000-02-29',
    'appointment_at' => '2026-01-15T10:30',
    'timezone' => 'UTC',
    'role' => 'official',
    'notifications' => ['email'],
], ['attachment' => UploadedFile::fromBytes('evidence.txt', 'ok')]);
if ($wrongCsrf['valid'] || ($wrongCsrf['errors']['_csrf'] ?? null) !== 'invalid_csrf') throw new RuntimeException('form_csrf_not_enforced');

if (!$types->validate('date', '2024-02-29') || $types->validate('date', '2023-02-29')) throw new RuntimeException('date_type_validation_failed');
if (!$types->validate('datetime', '2026-01-15T16:30:00Z') || $types->validate('datetime', '2026-99-99T00:00:00Z')) throw new RuntimeException('datetime_type_validation_failed');
if (!$types->validate('timezone', 'America/Mexico_City') || $types->validate('timezone', 'Mars/Olympus')) throw new RuntimeException('timezone_type_validation_failed');
$throws(fn() => FormControl::date('2026-12-31', '2026-01-01'), 'form_control_date_range_invalid');
$throws(fn() => FormControl::timezone(['Mars/Olympus' => 'Mars']), 'form_control_timezone_invalid');
$throws(fn() => $form->uploadPolicy('role'), 'form_upload_policy_not_found');

echo "JAS ADVANCED FORMS: PASS\n";
