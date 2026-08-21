<?php

declare(strict_types=1);

namespace WaveSpeed\Exception;

/**
 * Sync mode was requested, but the server gave up waiting before the task
 * finished (API code 5004).
 *
 * The task is still processing. Poll `Client::getResult($taskId)` — or the
 * `resultUrl` the API handed back — to collect the outputs later.
 */
class SyncModeTimeoutException extends ApiException
{
    public function __construct(
        string $message,
        ?string $taskId = null,
        public readonly ?string $resultUrl = null,
        ?string $responseBody = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, null, $responseBody, $taskId, $previous);
    }
}
