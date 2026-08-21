<?php

declare(strict_types=1);

namespace WaveSpeed\Exception;

/**
 * The local wait budget ran out while polling for a result.
 *
 * The task itself keeps running server-side — fetch it later with
 * `Client::getResult($taskId)`.
 */
class TimeoutException extends \RuntimeException implements WaveSpeedException
{
    public function __construct(
        string $message,
        public readonly ?string $taskId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
