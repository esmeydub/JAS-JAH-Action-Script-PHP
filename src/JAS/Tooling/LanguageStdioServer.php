<?php

declare(strict_types=1);

namespace Jah\JAS\Tooling;

use Jah\JAS\Transport\FrameProtocol;
use Throwable;

final class LanguageStdioServer
{
    public function __construct(
        private readonly LanguageBinaryService $service,
        private readonly FrameProtocol $frames = new FrameProtocol(8_389_256),
    ) {}

    /** @param resource $input @param resource $output */
    public function serve(mixed $input, mixed $output): int
    {
        try {
            while (!$this->service->exited()) {
                $packet = $this->frames->read($input);
                foreach ($this->service->handle($packet) as $response) $this->frames->write($output, $response);
            }
            return 0;
        } catch (Throwable) {
            return $this->service->exited() ? 0 : 1;
        }
    }
}
