<?php

declare(strict_types=1);

namespace WaveSpeed\Exception;

/**
 * The SDK was asked to do something it has not been configured for —
 * most commonly a call made without an API key.
 */
class ConfigurationException extends \InvalidArgumentException implements WaveSpeedException
{
}
