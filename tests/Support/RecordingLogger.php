<?php

declare(strict_types=1);

namespace WaveSpeed\Tests\Support;

use WaveSpeed\Support\Logger;

/**
 * Captures log lines so tests can assert on retry diagnostics.
 */
final class RecordingLogger implements Logger
{
    /** @var list<string> */
    public array $messages = [];

    public function log(string $message): void
    {
        $this->messages[] = $message;
    }

    public function contains(string $needle): bool
    {
        foreach ($this->messages as $message) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
