<?php

declare(strict_types=1);

namespace WaveSpeed\Support;

/**
 * Writes diagnostics to STDERR, which keeps them out of a CLI script's
 * stdout (where the actual result belongs).
 */
final class StderrLogger implements Logger
{
    /** @var resource */
    private $stream;

    /**
     * @param resource|null $stream Defaults to STDERR, or php://stderr when not running under CLI.
     */
    public function __construct($stream = null)
    {
        if ($stream === null) {
            $stream = defined('STDERR') ? STDERR : fopen('php://stderr', 'wb');
        }

        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('StderrLogger requires a writable stream resource.');
        }

        $this->stream = $stream;
    }

    public function log(string $message): void
    {
        fwrite($this->stream, '[wavespeed] ' . $message . PHP_EOL);
    }
}
