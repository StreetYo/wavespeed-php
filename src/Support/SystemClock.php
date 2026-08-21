<?php

declare(strict_types=1);

namespace WaveSpeed\Support;

/**
 * Real clock: wall time and a blocking sleep.
 */
final class SystemClock implements Clock
{
    public function now(): float
    {
        return microtime(true);
    }

    public function sleep(float $seconds): void
    {
        if ($seconds <= 0.0) {
            return;
        }

        usleep((int) round($seconds * 1_000_000));
    }
}
