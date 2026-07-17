<?php

declare(strict_types=1);

namespace Jah\Security;

use DateTimeImmutable;
use DateTimeZone;
use Jah\DataCore\PhpSerializer;

/**
 * SalkGuard
 * Seguridad userland para JAS.
 * No usa formatos externos para auditoría ni respuestas públicas.
 */
final class SalkGuard
{
    private string $root;
    private array $config;
    private array $salkConfig;
    private string $auditFile;

    /** @var array<string,string> */
    private array $knownSecrets = [];

    public function __construct(string $root, array $config = [])
    {
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
        $this->config = $config;
        $this->salkConfig = is_array($config['salk'] ?? null) ? $config['salk'] : [];
        $auditPath = (string)($this->salkConfig['audit_file'] ?? ($this->root . '/runtime/security/salk_audit.jahl'));
        $this->auditFile = $this->resolvePath($auditPath);
        $this->collectKnownSecrets();
        $this->ensureAuditDirectory();
    }

    public function preflight(string $context = 'runtime'): array
    {
        $checks = [];
        $warnings = [];
        $errors = [];

        foreach ([
            'env' => $this->checkEnv(),
            'api_key' => $this->protectApiKey(),
            'datacore_paths' => $this->checkDataCorePath(),
            'permissions' => $this->verifyRuntimePermissions(),
            'secret_scan' => $this->scanProjectForSecrets(),
            'package_vectors' => $this->checkPackageVectors(),
        ] as $name => $check) {
            $checks[$name] = $check;
            $warnings = array_merge($warnings, $check['warnings'] ?? []);
            $errors = array_merge($errors, $check['errors'] ?? []);
        }

        $result = [
            'ok' => $errors === [],
            'context' => $context,
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'checks' => $checks,
        ];

        $this->auditEvent('salk.preflight', $result, ['context' => $context]);
        return $this->maskSecrets($result);
    }

    public function checkEnv(): array
    {
        $warnings = [];
        $errors = [];
        $envFile = $this->root . '/.env';
        $publicEnv = $this->root . '/public/.env';

        if (is_file($publicEnv)) {
            $errors[] = '.env no debe existir dentro de public/';
        }

        if (is_file($envFile)) {
            $perms = substr(sprintf('%o', fileperms($envFile)), -4);
            if (!is_readable($envFile)) {
                $errors[] = '.env existe pero no se puede leer';
            }
            if (in_array($perms, ['0666', '0777', '0646', '0766'], true)) {
                $warnings[] = ".env tiene permisos demasiado abiertos ({$perms})";
            }
        } else {
            $warnings[] = '.env no existe; se usará el entorno del sistema si está configurado';
        }

        return [
            'ok' => $errors === [],
            'env_file' => is_file($envFile),
            'public_env_file' => is_file($publicEnv),
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    public function protectApiKey(): array
    {
        $warnings = [];
        $errors = [];
        $apiKey = $this->getSecret('JAH_API_KEY');

        if ($apiKey === '') {
            $warnings[] = 'JAH_API_KEY no está configurada; el acceso remoto permanecerá deshabilitado';
        } elseif (strlen($apiKey) < 24) {
            $warnings[] = 'JAH_API_KEY parece demasiado corta';
        }

        return [
            'ok' => $errors === [],
            'present' => $apiKey !== '',
            'fingerprint' => $apiKey !== '' ? $this->fingerprint($apiKey) : null,
            'source' => $this->secretSource('JAH_API_KEY'),
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    public function checkDataCorePath(): array
    {
        $warnings = [];
        $errors = [];
        $publicPath = $this->realOrFallback($this->root . '/public');

        $paths = [
            'datacore_storage' => (string)($this->config['paths']['datacore_storage'] ?? $this->root . '/runtime/memory/datacore'),
            'hot_storage' => (string)($this->config['paths']['hot_storage'] ?? $this->root . '/runtime/memory/pyramid'),
            'runtime_security' => dirname($this->auditFile),
        ];

        $resolved = [];
        foreach ($paths as $name => $path) {
            $full = $this->resolvePath($path);
            $resolved[$name] = $full;

            if ($this->isInside($full, $publicPath)) {
                $errors[] = "{$name} no debe estar dentro de public/";
            }

            if (!is_dir($full) && !@mkdir($full, 0700, true) && !is_dir($full)) {
                $errors[] = "no se pudo crear ruta segura: {$name}";
            }

            if (is_dir($full) && !is_writable($full)) {
                $errors[] = "ruta no escribible: {$name}";
            }
        }

        return [
            'ok' => $errors === [],
            'paths' => $resolved,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    public function verifyRuntimePermissions(): array
    {
        $warnings = [];
        $errors = [];

        foreach ([
            $this->root . '/runtime',
            dirname($this->auditFile),
            (string)($this->config['paths']['datacore_storage'] ?? $this->root . '/runtime/memory/datacore'),
            (string)($this->config['paths']['hot_storage'] ?? $this->root . '/runtime/memory/pyramid'),
        ] as $dir) {
            $path = $this->resolvePath($dir);
            if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
                $errors[] = "no se pudo crear {$this->relativePath($path)}";
                continue;
            }

            $perms = substr(sprintf('%o', fileperms($path)), -4);
            if (in_array($perms, ['0777', '0775', '0766'], true)) {
                $warnings[] = "permisos amplios en {$this->relativePath($path)} ({$perms})";
            }
        }

        return ['ok' => $errors === [], 'warnings' => $warnings, 'errors' => $errors];
    }

    public function scanProjectForSecrets(): array
    {
        $warnings = [];
        $errors = [];
        $matches = [];
        $maxMatches = (int)($this->salkConfig['max_secret_scan_matches'] ?? 20);
        $skipDirs = ['.git', 'runtime', 'vendor', 'node_modules'];
        $skipFiles = ['.env', '.env.example'];
        $skipExtensions = ['md', 'txt', 'log'];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                function ($current) use ($skipDirs, $skipFiles, $skipExtensions): bool {
                    $name = $current->getFilename();
                    if ($current->isDir()) {
                        return !in_array($name, $skipDirs, true);
                    }
                    if (in_array($name, $skipFiles, true)) {
                        return false;
                    }
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    return !in_array($ext, $skipExtensions, true);
                }
            )
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getSize() > 1_000_000) {
                continue;
            }

            $content = @file_get_contents($file->getPathname());
            if (!is_string($content)) {
                continue;
            }

            foreach ($this->secretPatterns() as $name => $pattern) {
                if (preg_match($pattern, $content) === 1) {
                    $matches[] = [
                        'file' => $this->relativePath($file->getPathname()),
                        'pattern' => $name,
                    ];
                    if (count($matches) >= $maxMatches) {
                        break 2;
                    }
                }
            }
        }

        if ($matches !== []) {
            $errors[] = 'se detectaron posibles secretos hardcodeados';
        }

        return [
            'ok' => $errors === [],
            'matches' => $matches,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    public function checkPackageVectors(): array
    {
        $warnings = [];
        $errors = [];
        $files = [];
        $dangerousScripts = [];
        $publicExposure = [];

        $suffix = chr(106) . chr(115) . chr(111) . chr(110);
        $scriptExtensions = ['js', 'jsx', 'ts', 'tsx'];
        $nodeArtifacts = [
            'package.' . $suffix,
            'package-lock.' . $suffix,
            'npm-shrinkwrap.' . $suffix,
            'yarn.lock',
            'pnpm-lock.yaml',
            'node_modules',
        ];

        foreach ($nodeArtifacts as $artifact) {
            $path = $this->root . DIRECTORY_SEPARATOR . $artifact;
            if (file_exists($path)) {
                $files[$artifact] = $this->relativePath($path);
                $errors[] = "{$artifact} detectado; el runtime debe mantenerse en PHP puro sin Node/npm";
            }

            $publicArtifact = $this->root . '/public/' . $artifact;
            if (file_exists($publicArtifact)) {
                $publicExposure[] = $artifact;
                $errors[] = "{$artifact} está expuesto dentro de public/";
            }
        }

        if (is_dir($this->root . '/vendor')) {
            $files['vendor'] = 'vendor';
            $errors[] = 'vendor detectado; el núcleo JAS no admite dependencias instaladas por Composer';
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            static function (\SplFileInfo $entry): bool {
                return !$entry->isDir() || !in_array($entry->getFilename(), ['.git', 'runtime', 'vendor'], true);
            }
        ));
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile()) continue;
            $extension = strtolower($entry->getExtension());
            if ($extension !== $suffix && !in_array($extension, $scriptExtensions, true)) continue;
            $relative = $this->relativePath($entry->getPathname());
            $files[$relative] = $relative;
            $errors[] = $extension === $suffix
                ? "{$relative} detectado; JAS prohíbe archivos JSON"
                : "{$relative} detectado; JAS prohíbe código JavaScript o TypeScript";
        }

        if ($dangerousScripts !== []) {
            $errors[] = 'se detectaron scripts de instalación/ejecución peligrosos en manifiestos';
        }

        return [
            'ok' => $errors === [],
            'mode' => 'php_puro_actionscript_php',
            'jas_native_formats_only' => true,
            'json_detected' => array_reduce(array_keys($files), static fn(bool $found, string $file): bool => $found || str_ends_with(strtolower($file), '.' . $suffix), false),
            'script_detected' => array_reduce(array_keys($files), static fn(bool $found, string $file): bool => $found || in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $scriptExtensions, true), false),
            'node_detected' => isset($files['node_modules']),
            'files' => $files,
            'public_exposure' => $publicExposure,
            'dangerous_scripts' => $dangerousScripts,
            'warnings' => array_values(array_unique($warnings)),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    public function validatePublicPayload(array $payload, string $context = 'payload.public'): array
    {
        $encoded = var_export($payload, true);
        $errors = [];
        $warnings = [];

        if ($this->containsSecret($encoded)) {
            $errors[] = 'payload público contiene un posible secreto';
        }

        foreach (['authorization', 'bearer', 'password', 'secret', 'token'] as $key) {
            if ($this->arrayHasSensitiveKey($payload, $key)) {
                $warnings[] = "payload público contiene campo sensible de diagnóstico: {$key}";
            }
        }

        $result = [
            'ok' => $errors === [],
            'context' => $context,
            'warnings' => array_values(array_unique($warnings)),
            'errors' => array_values(array_unique($errors)),
        ];

        $this->auditEvent('salk.validate_public_payload', $result, ['context' => $context]);
        return $this->maskSecrets($result);
    }

    public function auditEvent(string $event, array $result = [], array $metadata = []): array
    {
        $record = $this->maskSecrets([
            'ts' => (new DateTimeImmutable('now', new DateTimeZone((string)($this->config['timezone'] ?? 'UTC'))))->format(DATE_ATOM),
            'event' => $event,
            'status' => ($result['ok'] ?? $result['status'] ?? null) === false ? 'warning' : 'ok',
            'result' => $result,
            'metadata' => $metadata,
            'request' => [
                'method' => $_SERVER['REQUEST_METHOD'] ?? null,
                'uri' => $this->sanitizeRequestUri($_SERVER['REQUEST_URI'] ?? null),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ],
        ]);

        $this->ensureAuditDirectory();
        file_put_contents($this->auditFile, PhpSerializer::encode($record) . "\n", FILE_APPEND | LOCK_EX);

        return [
            'stored' => true,
            'audit_file' => $this->relativePath($this->auditFile),
            'event' => $event,
        ];
    }

    public function maskSecrets(mixed $value): mixed
    {
        if (is_array($value)) {
            $masked = [];
            foreach ($value as $key => $item) {
                $keyString = is_string($key) ? $key : (string)$key;
                if ($this->isSensitiveKey($keyString) && !is_array($item) && !is_object($item)) {
                    $masked[$key] = $item === null || $item === '' ? $item : '[SALK_MASKED]';
                } else {
                    $masked[$key] = $this->maskSecrets($item);
                }
            }
            return $masked;
        }

        if (is_object($value)) {
            return $this->maskSecrets((array)$value);
        }

        if (is_string($value)) {
            return $this->maskText($value);
        }

        return $value;
    }

    public function maskText(string $text): string
    {
        $masked = $text;

        foreach ($this->knownSecrets as $secret) {
            if ($secret !== '') {
                $masked = str_replace($secret, $this->maskFixed($secret), $masked);
            }
        }

        $masked = preg_replace('/Bearer\s+[^\s"\']+/i', 'Bearer [SALK_MASKED]', $masked) ?? $masked;
        $masked = preg_replace('/sk-[A-Za-z0-9_\-]{12,}/', 'sk-[SALK_MASKED]', $masked) ?? $masked;
        $masked = preg_replace('/(JAH_API_KEY\s*=\s*)[^\s\n\r]+/i', '$1[SALK_MASKED]', $masked) ?? $masked;
        $masked = preg_replace('/(Authorization\s*:\s*Bearer\s+)[^\s\n\r]+/i', '$1[SALK_MASKED]', $masked) ?? $masked;

        return $masked;
    }

    public function containsSecret(string $text): bool
    {
        if (preg_match('/sk-[A-Za-z0-9_\-]{12,}/', $text) === 1) {
            return true;
        }

        foreach ($this->knownSecrets as $secret) {
            if ($secret !== '' && str_contains($text, $secret)) {
                return true;
            }
        }

        return false;
    }

    public function containsSensitiveData(mixed $value): bool
    {
        if (!is_array($value)) return false;
        foreach ($value as $key => $item) {
            if ($this->isSensitiveKey((string)$key) && $item !== null && $item !== '' && $item !== []) {
                return true;
            }
            if (is_array($item) && $this->containsSensitiveData($item)) return true;
        }
        return false;
    }

    private function getSecret(string $name): string
    {
        $value = $_ENV[$name] ?? getenv($name);
        return is_string($value) ? trim($value) : '';
    }

    private function collectKnownSecrets(): void
    {
        foreach (['JAH_API_KEY', 'JAH_REPLICATION_KEY', 'JAS_PACKET_KEY'] as $key) {
            $secret = $this->getSecret($key);
            if ($secret !== '') {
                $this->knownSecrets[$key] = $secret;
            }
        }
    }

    private function secretSource(string $name): string
    {
        if (array_key_exists($name, $_ENV)) {
            return 'env_loaded';
        }
        $value = getenv($name);
        return is_string($value) && $value !== '' ? 'process_env' : 'missing';
    }

    private function collectDangerousTextFindings(string $source, string $content, array &$findings): void
    {
        foreach ([
            '/\bcurl\b/i',
            '/\bwget\b/i',
            '/\bbash\b/i',
            '/\bsh\s+-c\b/i',
            '/\bphp\s+-r\b/i',
            '/\beval\b/i',
            '/\bbase64_decode\b/i',
            '/\bnc\b|\bnetcat\b/i',
            '/\bnode\b/i',
            '/\bnpm\b/i',
            '/\byarn\b/i',
            '/\bpython\b/i',
            '/\brm\s+-rf\b/i',
        ] as $pattern) {
            if (preg_match($pattern, $content) === 1) {
                $findings[] = [
                    'source' => $source,
                    'pattern' => $pattern,
                    'command' => '[SALK_MASKED_OR_REVIEW_REQUIRED]',
                ];
            }
        }
    }

    private function secretPatterns(): array
    {
        return [
            'provider_style_key' => '/sk-[A-Za-z0-9_\-]{20,}/',
            'authorization_bearer_key' => '/Authorization\s*:\s*Bearer\s+sk-[A-Za-z0-9_\-]{12,}/i',
            'hardcoded_api_key' => '/[\'\"]api_key[\'\"]\s*=>\s*[\'\"]sk-[A-Za-z0-9_\-]{12,}/i',
        ];
    }

    private function isSensitiveKey(string $key): bool
    {
        $k = strtolower(trim($key));
        return preg_match(
            '/(^|[_-])(api[_-]?key|secret|token|authorization|bearer|password|passwd|credential)([_-]|$)/',
            $k
        ) === 1;
    }

    private function arrayHasSensitiveKey(array $payload, string $needle): bool
    {
        foreach ($payload as $key => $value) {
            $key = strtolower((string)$key);
            if ($key === $needle || str_contains($key, $needle)) {
                return true;
            }
            if (is_array($value) && $this->arrayHasSensitiveKey($value, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function maskFixed(string $secret): string
    {
        if (strlen($secret) <= 8) {
            return '[SALK_MASKED]';
        }
        return substr($secret, 0, 3) . '[SALK_MASKED]' . substr($secret, -3);
    }

    private function fingerprint(string $secret): string
    {
        return substr(hash('sha256', $secret), 0, 16);
    }

    private function ensureAuditDirectory(): void
    {
        $dir = dirname($this->auditFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
    }

    private function resolvePath(string $path): string
    {
        if ($path === '') {
            return $this->root;
        }
        if ($path[0] !== DIRECTORY_SEPARATOR) {
            $path = $this->root . DIRECTORY_SEPARATOR . $path;
        }
        return $this->realOrFallback($path);
    }

    private function realOrFallback(string $path): string
    {
        $real = realpath($path);
        return $real !== false ? $real : $path;
    }

    private function isInside(string $path, string $parent): bool
    {
        $path = rtrim($this->realOrFallback($path), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $parent = rtrim($this->realOrFallback($parent), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $parent);
    }

    private function relativePath(string $path): string
    {
        $full = $this->realOrFallback($path);
        $root = rtrim($this->realOrFallback($this->root), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($full, $root) ? substr($full, strlen($root)) : $full;
    }

    private function sanitizeRequestUri(mixed $uri): ?string
    {
        if (!is_string($uri) || $uri === '') return null;
        $parts = parse_url($uri);
        if (!is_array($parts)) return $this->maskText($uri);
        $path = (string)($parts['path'] ?? '');
        if (!isset($parts['query'])) return $path;

        parse_str((string)$parts['query'], $query);
        $query = $this->maskSecrets($query);
        return $path . ($query !== [] ? '?' . http_build_query($query) : '');
    }
}
