<?php

declare(strict_types=1);

namespace Jah\DataCore;

use RuntimeException;

final class DataCoreContinuityLock
{
    private int $depth = 0;
    private int $mode = 0;

    public function __construct(private readonly string $path)
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('datacore_continuity_directory_failed');
        }
    }

    public function shared(callable $operation): mixed
    {
        return $this->locked(LOCK_SH, $operation);
    }

    public function exclusive(callable $operation): mixed
    {
        return $this->locked(LOCK_EX, $operation);
    }

    private function locked(int $mode, callable $operation): mixed
    {
        if ($this->depth > 0) {
            if ($mode === LOCK_EX && $this->mode !== LOCK_EX) {
                throw new RuntimeException('datacore_continuity_lock_upgrade_forbidden');
            }
            $this->depth++;
            try {
                return $operation();
            } finally {
                $this->depth--;
            }
        }
        $handle = fopen($this->path, 'c+b');
        if ($handle === false || !flock($handle, $mode)) {
            throw new RuntimeException('datacore_continuity_lock_failed');
        }
        $this->depth = 1;
        $this->mode = $mode;
        try {
            return $operation();
        } finally {
            $this->depth = 0;
            $this->mode = 0;
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
