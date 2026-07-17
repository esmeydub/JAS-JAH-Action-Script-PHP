<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use RuntimeException;

final class UploadPolicy
{
    /** @var list<string> */
    public readonly array $allowedMimeTypes;

    public function __construct(
        public readonly string $name,
        array $allowedMimeTypes,
        public readonly int $maxBytes,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $name)) throw new RuntimeException('upload_policy_name_invalid');
        if (!array_is_list($allowedMimeTypes) || $allowedMimeTypes === [] || count($allowedMimeTypes) > 32) {
            throw new RuntimeException('upload_policy_mime_invalid');
        }
        foreach ($allowedMimeTypes as $mime) {
            if (!is_string($mime) || preg_match('#^[a-z0-9][a-z0-9.+-]{0,63}/[a-z0-9][a-z0-9.+-]{0,63}$#', $mime) !== 1) {
                throw new RuntimeException('upload_policy_mime_invalid');
            }
            if (in_array($mime, self::activeContentTypes(), true)) throw new RuntimeException('upload_policy_active_content_forbidden');
        }
        if ($maxBytes < 1 || $maxBytes > 67_108_864) throw new RuntimeException('upload_policy_size_invalid');
        $this->allowedMimeTypes = array_values(array_unique($allowedMimeTypes));
    }

    public function accepts(string $mime): bool { return in_array($mime, $this->allowedMimeTypes, true); }

    /** @return list<string> */
    private static function activeContentTypes(): array
    {
        return [
            'text/html', 'image/svg+xml', 'application/xhtml+xml',
            'application/javascript', 'text/javascript', 'application/x-httpd-php',
            'application/x-php', 'text/x-php',
        ];
    }
}
