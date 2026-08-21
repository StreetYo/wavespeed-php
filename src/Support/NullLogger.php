<?php

declare(strict_types=1);

namespace WaveSpeed\Support;

/**
 * Discards everything. The default, so a library never writes to a
 * consumer's output uninvited.
 */
final class NullLogger implements Logger
{
    public function log(string $message): void
    {
    }
}
