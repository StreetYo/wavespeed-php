<?php

declare(strict_types=1);

namespace WaveSpeed\Support;

/**
 * Minimal logging seam for retry and polling diagnostics.
 *
 * Deliberately not PSR-3: the SDK has no runtime dependencies, and a single
 * method is all it needs. Bridge to PSR-3 with `CallableLogger`.
 */
interface Logger
{
    public function log(string $message): void;
}
