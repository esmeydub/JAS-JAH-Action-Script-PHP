<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/support.php';

use Jah\JAS\Diagnostics\AgentDiagnosticReporter;
use Jah\JAS\Diagnostics\CoreIntegrityGuard;
use Jah\JAS\Diagnostics\DevelopmentDiagnosticReporter;
use Jah\JAS\Diagnostics\DiagnosticCode;
use Jah\JAS\Diagnostics\DiagnosticException;
use Jah\JAS\Diagnostics\DiagnosticFactory;
use Jah\JAS\Diagnostics\DiagnosticStore;
use Jah\JAS\Diagnostics\ErrorBoundary;
use Jah\JAS\Diagnostics\ExceptionMapper;
use Jah\JAS\Diagnostics\ProductionDiagnosticReporter;
use Jah\JAS\Jas;
use Jah\JAS\Tooling\ProjectScaffolder;
use Jah\JAS\Web\Html;
use Jah\JAS\Web\Request;
use Jah\JAS\Web\Response;
use Jah\JAS\Web\Router;

function jas_diagnostic_process(array $command, array $environment = []): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment + ['PATH' => (string) getenv('PATH')]);
    if (!is_resource($process)) throw new RuntimeException('diagnostic_process_failed');
    $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), (string) $out, (string) $error];
}

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$expect = static function (callable $operation, string $code) use ($assert): DiagnosticException {
    try { $operation(); } catch (DiagnosticException $error) {
        $assert($error->diagnostic()->code === $code, 'diagnostic_code_mismatch:' . $error->diagnostic()->code);
        return $error;
    }
    throw new RuntimeException('diagnostic_exception_missing:' . $code);
};
$base = sys_get_temp_dir() . '/jas_diagnostics_' . bin2hex(random_bytes(6));
mkdir($base, 0700, true);

try {
    $html = $expect(fn() => Html::element('a', ['style' => 'color:red'], 'bad'), DiagnosticCode::HTML_ATTRIBUTE_NOT_ALLOWED);
    $assert(($html->diagnostic()->context['attribute'] ?? null) === 'style', 'diagnostic_html_context_missing');
    $expect(static fn() => throw DiagnosticFactory::unsafeHtmlContent('script'), DiagnosticCode::UNSAFE_HTML_CONTENT);
    $expect(static fn() => throw DiagnosticFactory::coreIntegrityViolation('src/JAS/Core.php'), DiagnosticCode::CORE_INTEGRITY_VIOLATION);
    $mappedUnhandled = (new ExceptionMapper())->map(new RuntimeException('unexpected'));
    $assert($mappedUnhandled->code === DiagnosticCode::UNHANDLED_RUNTIME_ERROR, 'diagnostic_unhandled_mapping_failed');

    $application = Jas::application('Diagnostic Application')
        ->type('DiagnosticInput', ['id' => 'identifier'])
        ->type('DiagnosticOutput', ['id' => 'identifier', 'accepted' => 'bool'])
        ->domain('Diagnostics', 'diagnostic');
    $application->action('Diagnostics', 'diagnostic.run')
        ->input('DiagnosticInput')->output('DiagnosticOutput')->requires('diagnostic.run')->audit();

    $missing = $application->runtime(['actor' => ['diagnostic.run']], 'actor', $base . '/missing');
    $expect(fn() => $missing->execute('diagnostic.run', ['id' => 'REC-1']), DiagnosticCode::ACTION_NOT_REGISTERED);

    $input = $application->runtime(['actor' => ['diagnostic.run']], 'actor', $base . '/input');
    $input->handle('diagnostic.run', static fn(array $value): array => ['id' => $value['id'], 'accepted' => true]);
    $expect(fn() => $input->execute('diagnostic.run', ['id' => 'bad id']), DiagnosticCode::INPUT_TYPE_MISMATCH);

    $output = $application->runtime(['actor' => ['diagnostic.run']], 'actor', $base . '/output');
    $output->handle('diagnostic.run', static fn(array $value): array => ['id' => $value['id'], 'accepted' => 'yes']);
    $expect(fn() => $output->execute('diagnostic.run', ['id' => 'REC-2']), DiagnosticCode::OUTPUT_TYPE_MISMATCH);

    $denied = $application->runtime(['actor' => []], 'actor', $base . '/denied');
    $denied->handle('diagnostic.run', static fn(array $value): array => ['id' => $value['id'], 'accepted' => true]);
    $expect(fn() => $denied->execute('diagnostic.run', ['id' => 'REC-3']), DiagnosticCode::CAPABILITY_MISSING);

    $store = new DiagnosticStore($base . '/store');
    $boundary = new ErrorBoundary(new ExceptionMapper(), $store, new DevelopmentDiagnosticReporter());
    $router = new Router($input, null, $boundary);
    $routeResponse = $router->dispatch(new Request('GET', '/missing', requestId: 'diag-route'));
    $assert($routeResponse->status === 404 && str_contains($routeResponse->body, 'CODE=JAS-ROUTE-001'), 'diagnostic_route_boundary_failed');

    $secret = DiagnosticFactory::typeMismatch('input', 'DiagnosticInput')->diagnostic();
    $secretRecord = new \Jah\JAS\Diagnostics\Diagnostic(
        $secret->id, $secret->code, $secret->severity, $secret->title, $secret->message,
        $secret->component, '/private/server/app.php', 9,
        ['password' => 'NeverStoreThis', 'authorization' => 'Bearer hidden', 'safe' => 'visible'],
        $secret->suggestion, $secret->occurredAt,
    );
    $sanitized = $store->append($secretRecord);
    $assert(($sanitized->context['password'] ?? null) === '[REDACTED]' && $sanitized->file === 'app.php', 'diagnostic_secret_redaction_failed');
    $encodedStore = (string) file_get_contents($base . '/store/incidents.jasb');
    $assert(!str_contains($encodedStore, 'NeverStoreThis') && !str_contains($encodedStore, 'Bearer hidden'), 'diagnostic_secret_persisted');
    $symlinkStore = $base . '/symlink-store';
    mkdir($symlinkStore, 0700);
    file_put_contents($base . '/outside-incidents', 'protected');
    if (symlink($base . '/outside-incidents', $symlinkStore . '/incidents.jasb')) {
        try {
            new DiagnosticStore($symlinkStore);
            throw new RuntimeException('diagnostic_store_symlink_accepted');
        } catch (RuntimeException $error) {
            $assert($error->getMessage() === 'diagnostic_store_symlink_forbidden', 'diagnostic_store_symlink_wrong_error');
        }
    }
    $production = (new ProductionDiagnosticReporter())->report($sanitized, 422);
    $assert(!str_contains($production->body, 'DiagnosticInput') && str_contains($production->body, $sanitized->id), 'production_diagnostic_leaked_detail');
    $agent = (new AgentDiagnosticReporter())->render($sanitized);
    $assert(str_contains($agent, 'CODE=JAS-TYPE-001') && !str_contains($agent, 'NeverStoreThis'), 'agent_diagnostic_invalid');

    $core = $base . '/core';
    foreach (['src/JAS', 'src/DataCore', 'bin', 'app'] as $directory) mkdir($core . '/' . $directory, 0700, true);
    file_put_contents($core . '/src/JAS/Core.php', "<?php declare(strict_types=1);\n");
    file_put_contents($core . '/src/DataCore/Core.php', "<?php declare(strict_types=1);\n");
    file_put_contents($core . '/bin/jas', "#!/usr/bin/env php\n<?php declare(strict_types=1);\n");
    file_put_contents($core . '/app/bootstrap.php', "<?php declare(strict_types=1);\n");
    $guard = new CoreIntegrityGuard();
    $guard->seal($core);
    $assert($guard->verify($core)['valid'] === true, 'core_seal_initial_verify_failed');
    file_put_contents($core . '/src/JAS/Core.php', "<?php declare(strict_types=1); // changed\n");
    $verification = $guard->verify($core);
    $assert($verification['valid'] === false && ($verification['violations']['src/JAS/Core.php'] ?? null) === 'modified', 'core_integrity_change_missed');

    $cliStore = $base . '/cli-store';
    $cliDiagnostic = (new DiagnosticStore($cliStore))->append(DiagnosticFactory::strictTypesMissing('app/Unsafe.php')->diagnostic());
    [$lastCode, $lastOutput] = jas_diagnostic_process(
        [PHP_BINARY, dirname(__DIR__) . '/bin/jas', 'diagnose', '--last'],
        ['JAS_DIAGNOSTICS_DIR' => $cliStore],
    );
    $assert($lastCode === 0 && str_contains($lastOutput, $cliDiagnostic->id) && str_contains($lastOutput, 'CODE=JAS-PHP-001'), 'diagnostic_cli_last_failed');
    [$notFoundCode] = jas_diagnostic_process(
        [PHP_BINARY, dirname(__DIR__) . '/bin/jas', 'diagnose', '--id', 'JAS-20000101-000000000000'],
        ['JAS_DIAGNOSTICS_DIR' => $cliStore],
    );
    $assert($notFoundCode !== 0, 'diagnostic_cli_missing_exit_zero');
    [$coreCode, $coreOutput] = jas_diagnostic_process(
        [PHP_BINARY, dirname(__DIR__) . '/bin/jas', 'core:verify', $core],
        ['JAS_DIAGNOSTICS_DIR' => $cliStore],
    );
    $assert($coreCode === 1 && str_contains($coreOutput, 'CODE=JAS-CORE-001'), 'core_integrity_cli_diagnostic_failed');

    $project = $base . '/project';
    (new ProjectScaffolder())->create($project, 'Diagnostic Project');
    $target = $project . '/app/application.php';
    $source = (string) file_get_contents($target);
    file_put_contents($target, preg_replace('/declare\(strict_types=1\);\s*/', '', $source, 1));
    [$analyzeCode, $analyzeOutput] = jas_diagnostic_process(
        [PHP_BINARY, dirname(__DIR__) . '/bin/jas', 'analyze', $project],
        ['JAS_DIAGNOSTICS_DIR' => $cliStore],
    );
    $assert($analyzeCode === 1 && str_contains($analyzeOutput, 'Official diagnostic: JAS-PHP-001'), 'strict_types_diagnostic_not_integrated');

    echo "JAS DIAGNOSTICS: PASS\n";
} finally {
    jas_test_remove_tree($base);
}
