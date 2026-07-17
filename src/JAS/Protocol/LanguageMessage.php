<?php

declare(strict_types=1);

namespace Jah\JAS\Protocol;

use RuntimeException;

final class LanguageMessage
{
    public const REQUEST = 'request';
    public const NOTIFICATION = 'notification';
    public const RESPONSE = 'response';
    public const ERROR = 'error';

    /** @var array<string,int> */
    private const METHODS = [
        'initialize' => Opcodes::LANGUAGE_INITIALIZE,
        'initialized' => Opcodes::LANGUAGE_INITIALIZED,
        'textDocument/didOpen' => Opcodes::LANGUAGE_DOCUMENT_OPEN,
        'textDocument/didChange' => Opcodes::LANGUAGE_DOCUMENT_CHANGE,
        'textDocument/didClose' => Opcodes::LANGUAGE_DOCUMENT_CLOSE,
        'textDocument/hover' => Opcodes::LANGUAGE_HOVER,
        'textDocument/definition' => Opcodes::LANGUAGE_DEFINITION,
        'textDocument/references' => Opcodes::LANGUAGE_REFERENCES,
        'textDocument/prepareRename' => Opcodes::LANGUAGE_PREPARE_RENAME,
        'textDocument/rename' => Opcodes::LANGUAGE_RENAME,
        'textDocument/publishDiagnostics' => Opcodes::LANGUAGE_DIAGNOSTICS,
        'shutdown' => Opcodes::LANGUAGE_SHUTDOWN,
        'exit' => Opcodes::LANGUAGE_EXIT,
    ];

    private const NOTIFICATIONS = [
        'initialized', 'textDocument/didOpen', 'textDocument/didChange',
        'textDocument/didClose', 'textDocument/publishDiagnostics', 'exit',
    ];

    public function __construct(
        public readonly string $kind,
        public readonly string $method,
        public readonly int|string|null $externalId,
        public readonly array $body,
    ) {
        if (!isset(self::METHODS[$method]) || !in_array($kind, [self::REQUEST, self::NOTIFICATION, self::RESPONSE, self::ERROR], true)) {
            throw new RuntimeException('language_message_invalid');
        }
        $isNotification = in_array($method, self::NOTIFICATIONS, true);
        if (($kind === self::NOTIFICATION) !== $isNotification && !in_array($kind, [self::RESPONSE, self::ERROR], true)) {
            throw new RuntimeException('language_message_direction_invalid');
        }
        if ($isNotification && in_array($kind, [self::RESPONSE, self::ERROR], true)) {
            throw new RuntimeException('language_message_direction_invalid');
        }
        if ($kind === self::NOTIFICATION && $externalId !== null) throw new RuntimeException('language_notification_id_forbidden');
        if ($kind !== self::NOTIFICATION && !$this->validId($externalId)) throw new RuntimeException('language_request_id_invalid');
        if ($kind === self::REQUEST || $kind === self::NOTIFICATION) $this->validateBody($method, $body);
        if ($kind === self::ERROR) {
            if (!isset($body['code'], $body['message']) || !is_int($body['code']) || !is_string($body['message'])
                || strlen($body['message']) > 512) throw new RuntimeException('language_error_invalid');
        }
    }

    public function opcode(): int
    {
        return match ($this->kind) {
            self::RESPONSE => Opcodes::LANGUAGE_RESPONSE,
            self::ERROR => Opcodes::LANGUAGE_ERROR,
            default => self::METHODS[$this->method],
        };
    }

    public function toArray(): array
    {
        return ['schema' => 'JAS_LANGUAGE_1', 'kind' => $this->kind, 'method' => $this->method, 'external_id' => $this->externalId, 'body' => $this->body];
    }

    public static function fromArray(array $message): self
    {
        if (($message['schema'] ?? null) !== 'JAS_LANGUAGE_1' || !is_array($message['body'] ?? null)) {
            throw new RuntimeException('language_message_schema_invalid');
        }
        return new self(
            (string) ($message['kind'] ?? ''), (string) ($message['method'] ?? ''),
            $message['external_id'] ?? null, $message['body'],
        );
    }

    private function validId(mixed $id): bool
    {
        return (is_int($id) && $id >= 0) || (is_string($id) && $id !== '' && strlen($id) <= 128 && preg_match('//u', $id) === 1);
    }

    private function validateBody(string $method, array $body): void
    {
        $required = match ($method) {
            'initialize' => ['workspace_uri', 'process_id', 'position_encodings'],
            'textDocument/didOpen' => ['uri', 'version', 'language_id', 'content'],
            'textDocument/didChange' => ['uri', 'version', 'changes'],
            'textDocument/didClose' => ['uri'],
            'textDocument/hover', 'textDocument/definition', 'textDocument/references', 'textDocument/prepareRename' => ['uri', 'version', 'line', 'character', 'position_encoding'],
            'textDocument/rename' => ['uri', 'version', 'line', 'character', 'position_encoding', 'new_name'],
            'textDocument/publishDiagnostics' => ['uri', 'version', 'diagnostics'],
            default => [],
        };
        if (array_diff($required, array_keys($body)) !== [] || array_diff(array_keys($body), $required) !== []) {
            throw new RuntimeException('language_message_fields_invalid');
        }
        foreach (['workspace_uri', 'uri', 'language_id', 'content', 'position_encoding', 'new_name'] as $field) {
            if (array_key_exists($field, $body) && (!is_string($body[$field]) || strlen($body[$field]) > 4_194_304 || preg_match('//u', $body[$field]) !== 1)) {
                throw new RuntimeException('language_message_field_invalid');
            }
        }
        foreach (['version', 'line', 'character', 'process_id'] as $field) {
            if (array_key_exists($field, $body) && $body[$field] !== null
                && (!is_int($body[$field]) || $body[$field] < 0 || $body[$field] > 2_147_483_647)) {
                throw new RuntimeException('language_message_field_invalid');
            }
        }
        foreach (['position_encodings', 'changes', 'diagnostics'] as $field) {
            if (array_key_exists($field, $body) && (!is_array($body[$field]) || !array_is_list($body[$field]) || count($body[$field]) > 4_096)) {
                throw new RuntimeException('language_message_field_invalid');
            }
        }
        if (isset($body['workspace_uri']) && ($body['workspace_uri'] === '' || strlen($body['workspace_uri']) > 4_096)) {
            throw new RuntimeException('language_message_field_invalid');
        }
        if (isset($body['uri']) && ($body['uri'] === '' || strlen($body['uri']) > 4_096)) {
            throw new RuntimeException('language_message_field_invalid');
        }
        if (isset($body['position_encoding']) && !in_array($body['position_encoding'], ['utf-8', 'utf-16', 'utf-32'], true)) {
            throw new RuntimeException('language_message_field_invalid');
        }
        if (isset($body['position_encodings'])) {
            if ($body['position_encodings'] === []) throw new RuntimeException('language_message_field_invalid');
            foreach ($body['position_encodings'] as $encoding) {
                if (!is_string($encoding) || !in_array($encoding, ['utf-8', 'utf-16', 'utf-32'], true)) {
                    throw new RuntimeException('language_message_field_invalid');
                }
            }
        }
        if (isset($body['changes'])) {
            if ($body['changes'] === []) throw new RuntimeException('language_message_field_invalid');
            foreach ($body['changes'] as $change) {
                if (!is_array($change) || array_keys($change) !== ['text'] || !is_string($change['text'])
                    || strlen($change['text']) > 4_194_304 || preg_match('//u', $change['text']) !== 1) {
                    throw new RuntimeException('language_message_field_invalid');
                }
            }
        }
        if (isset($body['diagnostics'])) {
            foreach ($body['diagnostics'] as $diagnostic) {
                $keys = ['code', 'severity', 'message', 'start_line', 'start_character', 'end_line', 'end_character'];
                if (!is_array($diagnostic) || array_diff($keys, array_keys($diagnostic)) !== []
                    || array_diff(array_keys($diagnostic), $keys) !== []
                    || !is_string($diagnostic['code']) || !is_string($diagnostic['message'])
                    || strlen($diagnostic['code']) > 64 || strlen($diagnostic['message']) > 1_024
                    || !is_int($diagnostic['severity']) || $diagnostic['severity'] < 1 || $diagnostic['severity'] > 4) {
                    throw new RuntimeException('language_message_field_invalid');
                }
                foreach (['start_line', 'start_character', 'end_line', 'end_character'] as $position) {
                    if (!is_int($diagnostic[$position]) || $diagnostic[$position] < 0 || $diagnostic[$position] > 2_147_483_647) {
                        throw new RuntimeException('language_message_field_invalid');
                    }
                }
            }
        }
    }
}
