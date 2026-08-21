<?php

declare(strict_types=1);

namespace WaveSpeed\Exception;

/**
 * An upload referenced a path that does not exist or cannot be read.
 */
class FileNotFoundException extends \InvalidArgumentException implements WaveSpeedException
{
}
