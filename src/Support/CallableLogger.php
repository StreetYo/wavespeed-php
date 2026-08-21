<?php

declare(strict_types=1);

namespace WaveSpeed\Support;

/**
 * Adapts any callable into a `Logger` — the bridge to PSR-3 or a framework
 * logger:
 *
 *     new CallableLogger(fn (string $m) => $psrLogger->warning($m));
 */
final class CallableLogger implements Logger
{
    /** @var callable(string): void */
    private $handler;

    /**
     * @param callable(string): void $handler
     */
    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    public function log(string $message): void
    {
        ($this->handler)($message);
    }
}
