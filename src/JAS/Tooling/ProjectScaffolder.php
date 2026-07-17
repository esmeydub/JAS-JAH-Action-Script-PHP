<?php

declare(strict_types=1);

namespace Jah\JAS\Tooling;

use RuntimeException;

final class ProjectScaffolder
{
    public function create(string $directory, string $applicationName): array
    {
        if ($directory === '' || str_contains($directory, "\0")) throw new RuntimeException('scaffold_directory_invalid');
        if (!preg_match('/^[A-Z][A-Za-z0-9 _-]{2,127}$/', $applicationName)) throw new RuntimeException('scaffold_application_name_invalid');
        if (is_dir($directory) && (scandir($directory) ?: []) !== ['.', '..']) throw new RuntimeException('scaffold_directory_not_empty');
        foreach (['app/Actions', 'app/Domains', 'app/Events', 'app/Types', 'app/Web', 'config', 'public', 'runtime', 'tests'] as $relative) {
            $path = rtrim($directory, '/') . '/' . $relative;
            if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new RuntimeException('scaffold_directory_create_failed');
        }
        $files = [
            'app/application.php' => $this->applicationTemplate($applicationName),
            'public/index.php' => $this->publicTemplate(),
            'config/security.php' => $this->securityTemplate(),
            'tests/smoke.php' => $this->testTemplate(),
            '.env.example' => "JAS_ENV=development\nJAS_MASTER_KEY=\n",
            '.gitignore' => "/.env\n/runtime/*\n!/runtime/.gitkeep\n",
            'runtime/.gitkeep' => '',
            'README.md' => "# {$applicationName}\n\nAplicación organizada y gobernada por JAS.\n",
        ];
        $created = [];
        foreach ($files as $relative => $content) {
            $path = rtrim($directory, '/') . '/' . $relative;
            if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) throw new RuntimeException('scaffold_file_write_failed');
            $created[] = $path;
        }
        return $created;
    }

    public function domain(string $project, string $name, string $prefix): string
    {
        if (!preg_match('/^[A-Z][A-Za-z0-9]{1,63}$/', $name) || !preg_match('/^[a-z][a-z0-9_]{1,63}$/', $prefix)) throw new RuntimeException('scaffold_domain_invalid');
        $path = rtrim($project, '/') . '/app/Domains/' . $name . '.php';
        return $this->writeNew($path, "<?php\n\ndeclare(strict_types=1);\n\nreturn ['name' => '{$name}', 'prefix' => '{$prefix}', 'dependencies' => []];\n");
    }

    public function type(string $project, string $name): string
    {
        if (!preg_match('/^[A-Z][A-Za-z0-9_]{1,127}$/', $name)) throw new RuntimeException('scaffold_type_invalid');
        $path = rtrim($project, '/') . '/app/Types/' . $name . '.php';
        return $this->writeNew($path, "<?php\n\ndeclare(strict_types=1);\n\nreturn ['name' => '{$name}', 'fields' => ['id' => 'identifier'], 'strict' => true];\n");
    }

    public function event(string $project, string $domain, string $name, string $payload, int $version = 1): string
    {
        if (!preg_match('/^[A-Z][A-Za-z0-9]{1,63}$/', $domain)
            || !preg_match('/^[a-z][a-z0-9_.:-]{2,255}$/', $name)
            || !preg_match('/^[A-Z][A-Za-z0-9_]{1,127}$/', $payload)
            || $version < 1 || $version > 1_000_000) throw new RuntimeException('scaffold_event_invalid');
        $file = str_replace(' ', '', ucwords(str_replace(['.', ':', '-'], ' ', $name))) . 'V' . $version . '.php';
        $path = rtrim($project, '/') . '/app/Events/' . $file;
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn ['domain' => '{$domain}', 'name' => '{$name}', 'payload' => '{$payload}', 'version' => {$version}];\n";
        return $this->writeNew($path, $content);
    }

    public function action(string $project, string $domain, string $name, ?string $input = null, ?string $output = null, ?string $capability = null): string
    {
        if (!preg_match('/^[A-Z][A-Za-z0-9]{1,63}$/', $domain) || !preg_match('/^[a-z][a-z0-9_.:-]{2,255}$/', $name)) throw new RuntimeException('scaffold_action_invalid');
        if ($input === null || $output === null) {
            $types = glob(rtrim($project, '/') . '/app/Types/*.php') ?: [];
            if (count($types) !== 1) throw new RuntimeException('scaffold_action_types_required');
            $definition = (new PhpDefinitionReader())->read($types[0]);
            $inferred = $definition['name'] ?? null;
            if (!is_string($inferred)) throw new RuntimeException('scaffold_action_types_required');
            $input ??= $inferred;
            $output ??= $inferred;
        }
        foreach ([$input, $output] as $type) if (!preg_match('/^[A-Z][A-Za-z0-9_]{1,127}$/', $type)) throw new RuntimeException('scaffold_action_type_invalid');
        $capability ??= $name;
        if (!preg_match('/^[a-z][a-z0-9_.:*\-]{2,255}$/', $capability)) throw new RuntimeException('scaffold_action_capability_invalid');
        $file = str_replace(' ', '', ucwords(str_replace(['.', ':', '-'], ' ', $name))) . '.php';
        $path = rtrim($project, '/') . '/app/Actions/' . $file;
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn ['domain' => '{$domain}', 'name' => '{$name}', 'input' => '{$input}', 'output' => '{$output}', 'capability' => '{$capability}', 'audit' => true];\n";
        return $this->writeNew($path, $content);
    }

    private function writeNew(string $path, string $content): string
    {
        if (!is_dir(dirname($path))) throw new RuntimeException('scaffold_project_invalid');
        $handle = @fopen($path, 'xb');
        if ($handle === false) throw new RuntimeException('scaffold_file_exists');
        try { if (fwrite($handle, $content) !== strlen($content)) throw new RuntimeException('scaffold_file_write_failed'); }
        finally { fclose($handle); }
        return $path;
    }

    private function applicationTemplate(string $name): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nuse Jah\\JAS\\Tooling\\GeneratedApplicationLoader;\n\nreturn (new GeneratedApplicationLoader())->load(dirname(__DIR__), '{$name}');\n";
    }
    private function publicTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

$jasRoot = rtrim((string) getenv('JAS_ROOT'), '/');
if ($jasRoot === '') {
    throw new RuntimeException('JAS_ROOT is required');
}
require $jasRoot . '/app/bootstrap.php';

echo 'JAS_READY';
PHP
            . "\n";
    }
    private function securityTemplate(): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn ['roles' => ['admin' => ['*']]];\n";
    }
    private function testTemplate(): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\n\$app = require dirname(__DIR__) . '/app/application.php';\n\$app->validate();\necho \"JAS APP: PASS\\n\";\n";
    }
}
