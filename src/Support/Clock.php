<?php

declare(strict_types=1);

namespace WaveSpeed\Support;

/**
 * Time seam used by polling and retry logic.
 *
 * Tests supply a fake so a 36000-second timeout can be exercised instantly.
 */
interface Clock
{
    /**
     * Current time as a float number of seconds.
     */
    public function now(): float;

    /**
     * Pause the current execution for the given number of seconds.
     */
    public function sleep(float $seconds): void;
}
