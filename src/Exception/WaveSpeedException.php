<?php

declare(strict_types=1);

namespace WaveSpeed\Exception;

/**
 * Marker interface implemented by every exception this SDK throws.
 *
 * Catch this to handle any SDK failure without also catching unrelated
 * runtime errors from the surrounding application.
 */
interface WaveSpeedException extends \Throwable
{
}
