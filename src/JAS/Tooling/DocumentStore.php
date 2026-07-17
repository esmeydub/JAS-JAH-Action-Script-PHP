<?php

declare(strict_types=1);

namespace Jah\JAS\Tooling;

use RuntimeException;

/** Session-local authoritative content for documents managed by an editor. */
final class DocumentStore
{
    private readonly string $workspace;
    /** @var array<string,array{uri:string,path:string,relative:string,version:int,content:string,hash:string}> */
    private array $documents = [];
    private int $totalBytes = 0;

    public function __construct(
        string $workspace,
        private readonly int $maximumDocuments = 128,
        private readonly int $maximumDocumentBytes = 4_194_304,
        private readonly int $maximumTotalBytes = 33_554_432,
        private readonly int $maximumLines = 200_000,
    ) {
        if (is_link($workspace)) throw new RuntimeException('language_workspace_invalid');
        $root = realpath($workspace);
        if ($root === false || !is_dir($root) || is_link($root)) throw new RuntimeException('language_workspace_invalid');
        if ($maximumDocuments < 1 || $maximumDocuments > 4_096
            || $maximumDocumentBytes < 1_024 || $maximumDocumentBytes > 16_777_216
            || $maximumTotalBytes < $maximumDocumentBytes || $maximumTotalBytes > 268_435_456
            || $maximumLines < 100 || $maximumLines > 1_000_000) {
            throw new RuntimeException('language_document_limits_invalid');
        }
        $this->workspace = rtrim($root, '/');
    }

    public function open(string $uri, int $version, string $content): array
    {
        if ($version < 0) throw new RuntimeException('language_document_version_invalid');
        $document = $this->identity($uri);
        if (isset($this->documents[$document['path']])) throw new RuntimeException('language_document_already_open');
        if (count($this->documents) >= $this->maximumDocuments) throw new RuntimeException('language_document_limit_exceeded');
        $this->validateContent($content, 0);
        $stored = $document + ['version' => $version, 'content' => $content, 'hash' => hash('sha256', $content)];
        $this->documents[$document['path']] = $stored;
        $this->totalBytes += strlen($content);
        return $stored;
    }

    public function change(string $uri, int $version, string $content): array
    {
        $identity = $this->identity($uri);
        $current = $this->documents[$identity['path']] ?? throw new RuntimeException('language_document_not_open');
        if ($version <= $current['version']) throw new RuntimeException('language_document_version_stale');
        $previousBytes = strlen($current['content']);
        $this->validateContent($content, $previousBytes);
        $current['version'] = $version;
        $current['content'] = $content;
        $current['hash'] = hash('sha256', $content);
        $this->documents[$identity['path']] = $current;
        $this->totalBytes += strlen($content) - $previousBytes;
        return $current;
    }

    public function close(string $uri): void
    {
        $identity = $this->identity($uri);
        $current = $this->documents[$identity['path']] ?? throw new RuntimeException('language_document_not_open');
        $this->totalBytes -= strlen($current['content']);
        unset($this->documents[$identity['path']]);
    }

    public function document(string $uri): ?array
    {
        $identity = $this->identity($uri);
        return $this->documents[$identity['path']] ?? null;
    }

    public function source(string $uri): string
    {
        $identity = $this->identity($uri);
        if (isset($this->documents[$identity['path']])) return $this->documents[$identity['path']]['content'];
        $source = @file_get_contents($identity['path']);
        if (!is_string($source) || strlen($source) > $this->maximumDocumentBytes || preg_match('//u', $source) !== 1) {
            throw new RuntimeException('language_document_read_failed');
        }
        return $source;
    }

    public function relative(string $uri): string { return $this->identity($uri)['relative']; }

    public function uriForRelative(string $relative): string
    {
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '..') || str_starts_with($relative, '/')) {
            throw new RuntimeException('language_document_path_invalid');
        }
        $path = $this->workspace . '/' . $relative;
        return $this->identity('file://' . str_replace('%2F', '/', rawurlencode($path)))['uri'];
    }

    public function sourceForRelative(string $relative): string { return $this->source($this->uriForRelative($relative)); }

    public function workspace(): string { return $this->workspace; }

    /** @return array<string,string> relative path => content */
    public function sourcesFor(string $workspace): array
    {
        $root = realpath($workspace);
        if ($root === false || !hash_equals($this->workspace, rtrim($root, '/'))) throw new RuntimeException('language_workspace_mismatch');
        $sources = [];
        foreach ($this->documents as $document) $sources[$document['relative']] = $document['content'];
        ksort($sources, SORT_STRING);
        return $sources;
    }

    public function count(): int { return count($this->documents); }
    public function totalBytes(): int { return $this->totalBytes; }
    public function clear(): void { $this->documents = []; $this->totalBytes = 0; }

    /** @return array{uri:string,path:string,relative:string} */
    private function identity(string $uri): array
    {
        if ($uri === '' || strlen($uri) > 8_192 || preg_match('//u', $uri) !== 1
            || preg_match('/%(?![A-Fa-f0-9]{2})/', $uri) === 1) throw new RuntimeException('language_document_uri_invalid');
        $parts = parse_url($uri);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'file'
            || !in_array($parts['host'] ?? '', ['', 'localhost'], true)
            || isset($parts['user'], $parts['pass'], $parts['port'], $parts['query'], $parts['fragment'])) {
            throw new RuntimeException('language_document_uri_invalid');
        }
        $path = rawurldecode((string) ($parts['path'] ?? ''));
        if ($path === '' || !str_starts_with($path, '/') || str_contains($path, "\0")) throw new RuntimeException('language_document_uri_invalid');
        $resolved = realpath($path);
        if ($resolved !== false && !is_file($resolved)) throw new RuntimeException('language_document_path_invalid');
        if ($resolved === false) {
            $parent = realpath(dirname($path));
            $name = basename($path);
            if ($parent === false || $name === '' || $name === '.' || $name === '..' || is_link($path)) {
                throw new RuntimeException('language_document_path_invalid');
            }
            $resolved = rtrim($parent, '/') . '/' . $name;
        }
        if ($resolved === $this->workspace || !str_starts_with($resolved, $this->workspace . '/')) {
            throw new RuntimeException('language_document_outside_workspace');
        }
        $relative = ltrim(substr($resolved, strlen($this->workspace)), '/');
        if ($relative === '' || strlen($relative) > 4_096) throw new RuntimeException('language_document_path_invalid');
        return ['uri' => 'file://' . str_replace('%2F', '/', rawurlencode($resolved)), 'path' => $resolved, 'relative' => $relative];
    }

    private function validateContent(string $content, int $replacedBytes): void
    {
        $bytes = strlen($content);
        if ($bytes > $this->maximumDocumentBytes || preg_match('//u', $content) !== 1
            || substr_count($content, "\n") + 1 > $this->maximumLines
            || $this->totalBytes - $replacedBytes + $bytes > $this->maximumTotalBytes) {
            throw new RuntimeException('language_document_content_invalid');
        }
    }
}
