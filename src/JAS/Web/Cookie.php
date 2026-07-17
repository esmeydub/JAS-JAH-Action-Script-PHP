<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use InvalidArgumentException;

final class Cookie
{
    public function __construct(
        public readonly string $name,
        public readonly string $value,
        public readonly int $expiresAt,
        public readonly int $maxAge,
        public readonly string $sameSite = 'Strict',
        public readonly bool $secure = true,
        public readonly bool $httpOnly = true,
    ) {
        if (!preg_match('/^__Host-[A-Za-z0-9_-]{1,96}$/', $name)) throw new InvalidArgumentException('cookie_name_invalid');
        if ($value !== '' && preg_match('/^[A-Za-z0-9._~-]{1,4096}$/', $value) !== 1) throw new InvalidArgumentException('cookie_value_invalid');
        if ($maxAge < 0 || !in_array($sameSite, ['Strict', 'Lax'], true)) throw new InvalidArgumentException('cookie_attributes_invalid');
        if (!$secure || !$httpOnly) throw new InvalidArgumentException('cookie_security_downgrade_forbidden');
    }

    public function header(): string
    {
        return $this->name . '=' . $this->value
            . '; Expires=' . gmdate('D, d M Y H:i:s \G\M\T', $this->expiresAt)
            . '; Max-Age=' . $this->maxAge
            . '; Path=/; Secure; HttpOnly; SameSite=' . $this->sameSite;
    }
}
