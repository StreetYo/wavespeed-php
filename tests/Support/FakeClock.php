<?php

declare(strict_types=1);

namespace WaveSpeed\Tests\Support;

use WaveSpeed\Support\Clock;

/**
 * Deterministic clock: `sleep()` records the duration and advances virtual
 * time instead of blocking, so a 36000-second timeout is testable instantly.
 */
final class FakeClock implements Clock
{
    /** @var list<float> */
    public array $sleeps = [];

    public function __construct(
        private float $time = 1_000_000.0,
        /** Extra seconds each now() call advances, simulating time spent in I/O. */
        private readonly float $tickPerCall = 0.0,
    ) {
    }

    public function now(): float
    {
        $now = $this->time;
        $this->time += $this->tickPerCall;

        return $now;
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->time += $seconds;
    }

    public function advance(float $seconds): void
    {
        $this->time += $seconds;
    }

    public function totalSlept(): float
    {
        return array_sum($this->sleeps);
    }

    public function sleepCount(): int
    {
        return count($this->sleeps);
    }
}
