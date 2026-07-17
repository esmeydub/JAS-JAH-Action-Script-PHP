<?php

declare(strict_types=1);

namespace Jah\JAS\Tooling;

use Jah\JAS\Protocol\LanguageMessage;
use Jah\JAS\Protocol\LanguageProtocolCodec;
use RuntimeException;
use Throwable;

/** Stateful semantic service behind the external C++ LSP bridge. */
final class LanguageBinaryService
{
    private const CREATED = 0;
    private const INITIALIZED = 1;
    private const ACTIVE = 2;
    private const SHUTDOWN = 3;
    private const EXITED = 4;

    private int $state = self::CREATED;
    private readonly DocumentStore $documents;
    private readonly JasLanguageEngine $engine;
    private readonly LanguagePositionCodec $positions;
    private ?string $sessionId = null;
    private string $positionEncoding = 'utf-16';
    /** @var array<string,true> */
    private array $correlations = [];

    public function __construct(
        private readonly LanguageProtocolCodec $protocol,
        string $workspace,
        ?DocumentStore $documents = null,
        ?LanguagePositionCodec $positions = null,
        private readonly int $maximumMessages = 100_000,
        private readonly int $clockSkewSeconds = 60,
    ) {
        if ($maximumMessages < 100 || $maximumMessages > 1_000_000 || $clockSkewSeconds < 1 || $clockSkewSeconds > 300) {
            throw new RuntimeException('language_service_limits_invalid');
        }
        $this->documents = $documents ?? new DocumentStore($workspace);
        if (!hash_equals($this->documents->workspace(), rtrim((string) realpath($workspace), '/'))) {
            throw new RuntimeException('language_workspace_mismatch');
        }
        $this->positions = $positions ?? new LanguagePositionCodec();
        $this->engine = new JasLanguageEngine(documents: $this->documents);
    }

    /** @return list<string> zero, one or more JASB packets */
    public function handle(string $packet): array
    {
        $decoded = $this->protocol->decode($packet);
        $correlation = $decoded['correlation_id'];
        if (abs(time() - $decoded['timestamp']) > $this->clockSkewSeconds) throw new RuntimeException('language_message_expired');
        if (isset($this->correlations[$correlation])) throw new RuntimeException('language_message_replay');
        if (count($this->correlations) >= $this->maximumMessages) throw new RuntimeException('language_session_message_limit');
        $this->correlations[$correlation] = true;
        if ($this->sessionId !== null && !hash_equals($this->sessionId, $decoded['session_id'])) {
            throw new RuntimeException('language_session_mismatch');
        }
        $message = $decoded['message'];
        if ($this->state === self::CREATED && $message->method !== 'initialize' && $message->method !== 'exit') {
            return $message->kind === LanguageMessage::REQUEST ? [$this->error($message, $correlation, $decoded['session_id'], -32002, 'Server not initialized')] : [];
        }
        if ($this->state === self::SHUTDOWN && $message->method !== 'exit') {
            return $message->kind === LanguageMessage::REQUEST ? [$this->error($message, $correlation, $decoded['session_id'], -32600, 'Server is shutting down')] : [];
        }
        if ($this->state === self::EXITED) throw new RuntimeException('language_service_exited');
        try {
            return $this->dispatch($message, $correlation, $decoded['session_id']);
        } catch (Throwable) {
            if ($message->kind === LanguageMessage::NOTIFICATION) return [];
            return [$this->error($message, $correlation, $decoded['session_id'], -32602, 'Invalid language request')];
        }
    }

    public function exited(): bool { return $this->state === self::EXITED; }

    /** @return list<string> */
    private function dispatch(LanguageMessage $message, string $correlation, string $session): array
    {
        if ($message->method === 'initialize') return [$this->initialize($message, $correlation, $session)];
        if ($message->method === 'initialized') { if ($this->state !== self::INITIALIZED) throw new RuntimeException('language_lifecycle_invalid'); $this->state = self::ACTIVE; return []; }
        if ($message->method === 'shutdown') {
            if ($this->state !== self::ACTIVE) throw new RuntimeException('language_lifecycle_invalid');
            $this->state = self::SHUTDOWN;
            return [$this->response($message, $correlation, $session, [])];
        }
        if ($message->method === 'exit') { $this->documents->clear(); $this->state = self::EXITED; return []; }
        if ($this->state !== self::ACTIVE) throw new RuntimeException('language_lifecycle_invalid');
        return match ($message->method) {
            'textDocument/didOpen' => $this->open($message, $correlation, $session),
            'textDocument/didChange' => $this->change($message, $correlation, $session),
            'textDocument/didClose' => $this->close($message, $correlation, $session),
            'textDocument/hover' => [$this->response($message, $correlation, $session, $this->hover($message->body))],
            'textDocument/definition' => [$this->response($message, $correlation, $session, $this->definition($message->body))],
            'textDocument/references' => [$this->response($message, $correlation, $session, $this->references($message->body))],
            'textDocument/prepareRename' => [$this->response($message, $correlation, $session, $this->prepareRename($message->body))],
            'textDocument/rename' => [$this->response($message, $correlation, $session, $this->rename($message->body))],
            default => throw new RuntimeException('language_method_unsupported'),
        };
    }

    private function initialize(LanguageMessage $message, string $correlation, string $session): string
    {
        if ($this->state !== self::CREATED || $this->workspacePath((string) $message->body['workspace_uri']) !== $this->documents->workspace()) {
            throw new RuntimeException('language_initialize_invalid');
        }
        $encodings = $message->body['position_encodings'];
        $this->positionEncoding = (string) $encodings[0];
        $this->sessionId = $session;
        $this->state = self::INITIALIZED;
        return $this->response($message, $correlation, $session, [
            'server_name' => 'JAS Language Server', 'protocol_version' => 1,
            'position_encoding' => $this->positionEncoding, 'text_sync' => 'full',
            'hover' => true, 'definition' => true, 'references' => true,
            'prepare_rename' => true, 'rename' => true, 'diagnostics' => 'push',
        ]);
    }

    /** @return list<string> */
    private function open(LanguageMessage $message, string $correlation, string $session): array
    {
        $this->documents->open((string) $message->body['uri'], (int) $message->body['version'], (string) $message->body['content']);
        return [$this->diagnostics($message, $correlation, $session)];
    }

    /** @return list<string> */
    private function change(LanguageMessage $message, string $correlation, string $session): array
    {
        $change = $message->body['changes'][0] ?? throw new RuntimeException('language_change_missing');
        $this->documents->change((string) $message->body['uri'], (int) $message->body['version'], (string) $change['text']);
        return [$this->diagnostics($message, $correlation, $session)];
    }

    /** @return list<string> */
    private function close(LanguageMessage $message, string $correlation, string $session): array
    {
        $uri = (string) $message->body['uri'];
        $document = $this->documents->document($uri) ?? throw new RuntimeException('language_document_not_open');
        $this->documents->close($uri);
        $notification = new LanguageMessage(LanguageMessage::NOTIFICATION, 'textDocument/publishDiagnostics', null, [
            'uri' => $uri, 'version' => (int) $document['version'], 'diagnostics' => [],
        ]);
        return [$this->protocol->encode($notification, $this->correlation($correlation, 'diagnostics'), $session)];
    }

    private function hover(array $body): array
    {
        [$file, $line, $column] = $this->enginePosition($body);
        $hover = $this->engine->hover($this->documents->workspace(), $file, $line, $column);
        if ($hover === null) return ['hover' => null];
        return ['hover' => [
            'kind' => $hover['kind'], 'name' => $hover['name'], 'detail' => $hover['detail'],
            'location' => $this->location($hover['location']),
        ]];
    }

    private function definition(array $body): array
    {
        [$file, $line, $column] = $this->enginePosition($body);
        $location = $this->engine->definition($this->documents->workspace(), $file, $line, $column);
        return ['location' => $location === null ? null : $this->location($location)];
    }

    private function references(array $body): array
    {
        [$file, $line, $column] = $this->enginePosition($body);
        return ['locations' => array_map(fn(array $location): array => $this->location($location),
            $this->engine->references($this->documents->workspace(), $file, $line, $column))];
    }

    private function prepareRename(array $body): array
    {
        [$file, $line, $column] = $this->enginePosition($body);
        $hover = $this->engine->hover($this->documents->workspace(), $file, $line, $column);
        if ($hover === null) return ['range' => null, 'placeholder' => null];
        return ['range' => $this->range($hover['location']), 'placeholder' => $hover['name']];
    }

    private function rename(array $body): array
    {
        [$file, $line, $column] = $this->enginePosition($body);
        $plan = $this->engine->rename($this->documents->workspace(), $file, $line, $column, (string) $body['new_name'], false);
        $changes = [];
        foreach ($plan['changes'] as $change) {
            $uri = $this->documents->uriForRelative($change['file']);
            $document = $this->documents->document($uri);
            $changes[] = [
                'uri' => $uri, 'version' => $document['version'] ?? null,
                'range' => $this->range($change), 'new_text' => (string) $body['new_name'],
            ];
        }
        $files = [];
        foreach ($plan['files'] as $fileRename) $files[] = [
            'old_uri' => $this->documents->uriForRelative($fileRename['from']),
            'new_uri' => $this->uriForPotentialPath($fileRename['to']),
        ];
        return ['changes' => $changes, 'file_renames' => $files];
    }

    private function diagnostics(LanguageMessage $source, string $correlation, string $session): string
    {
        $report = $this->engine->diagnostics($this->documents->workspace());
        $uri = (string) $source->body['uri'];
        $relative = $this->documents->relative($uri);
        $items = [];
        foreach ($report['diagnostics'] as $diagnostic) {
            if ($diagnostic['file'] !== $relative) continue;
            $line = max(0, (int) $diagnostic['line'] - 1);
            $items[] = [
                'code' => (string) $diagnostic['code'], 'severity' => 1,
                'message' => $this->truncateUtf8((string) $diagnostic['message'], 1_024),
                'start_line' => $line, 'start_character' => 0,
                'end_line' => $line, 'end_character' => 0,
            ];
        }
        $document = $this->documents->document($uri);
        $notification = new LanguageMessage(LanguageMessage::NOTIFICATION, 'textDocument/publishDiagnostics', null, [
            'uri' => $uri, 'version' => (int) ($document['version'] ?? 0), 'diagnostics' => $items,
        ]);
        return $this->protocol->encode($notification, $this->correlation($correlation, 'diagnostics'), $session);
    }

    /** @return array{string,int,int} */
    private function enginePosition(array $body): array
    {
        $uri = (string) $body['uri'];
        if (($body['position_encoding'] ?? null) !== $this->positionEncoding) throw new RuntimeException('language_position_encoding_mismatch');
        $document = $this->documents->document($uri);
        if ($document !== null && (int) $body['version'] !== $document['version']) throw new RuntimeException('language_document_version_mismatch');
        $source = $this->documents->source($uri);
        $offset = $this->positions->offset($source, (int) $body['line'], (int) $body['character'], $this->positionEncoding);
        $position = $this->positions->position($source, $offset, 'utf-8');
        return [$this->documents->relative($uri), $position['line'] + 1, $position['character'] + 1];
    }

    private function location(array $location): array
    {
        return ['uri' => $this->documents->uriForRelative($location['file']), 'range' => $this->range($location)];
    }

    private function range(array $location): array
    {
        $source = $this->documents->sourceForRelative($location['file']);
        $startOffset = $this->positions->offset($source, (int) $location['line'] - 1, (int) $location['column'] - 1, 'utf-8');
        return [
            'start' => $this->positions->position($source, $startOffset, $this->positionEncoding),
            'end' => $this->positions->position($source, $startOffset + (int) $location['length'], $this->positionEncoding),
        ];
    }

    private function response(LanguageMessage $request, string $correlation, string $session, array $body): string
    {
        return $this->protocol->encode(new LanguageMessage(LanguageMessage::RESPONSE, $request->method, $request->externalId, $body), $this->correlation($correlation, 'response'), $session);
    }

    private function error(LanguageMessage $request, string $correlation, string $session, int $code, string $message): string
    {
        return $this->protocol->encode(new LanguageMessage(LanguageMessage::ERROR, $request->method, $request->externalId, ['code' => $code, 'message' => $message]), $this->correlation($correlation, 'error'), $session);
    }

    private function workspacePath(string $uri): string
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'file' || !in_array($parts['host'] ?? '', ['', 'localhost'], true)
            || isset($parts['user'], $parts['pass'], $parts['port'], $parts['query'], $parts['fragment'])
            || preg_match('/%(?![A-Fa-f0-9]{2})/', $uri) === 1) {
            throw new RuntimeException('language_workspace_uri_invalid');
        }
        $path = realpath(rawurldecode((string) ($parts['path'] ?? '')));
        if ($path === false || !is_dir($path)) throw new RuntimeException('language_workspace_uri_invalid');
        return rtrim($path, '/');
    }

    private function uriForPotentialPath(string $relative): string
    {
        $path = $this->documents->workspace() . '/' . $relative;
        $parent = realpath(dirname($path));
        if ($parent === false || !str_starts_with($parent, $this->documents->workspace() . '/')) throw new RuntimeException('language_rename_path_invalid');
        return 'file://' . str_replace('%2F', '/', rawurlencode($parent . '/' . basename($path)));
    }

    private function correlation(string $incoming, string $direction): string
    {
        return hash('sha256', $incoming . "\0" . $direction);
    }

    private function truncateUtf8(string $value, int $bytes): string
    {
        if (strlen($value) <= $bytes) return $value;
        $value = substr($value, 0, $bytes);
        while ($value !== '' && preg_match('//u', $value) !== 1) $value = substr($value, 0, -1);
        return $value;
    }
}
