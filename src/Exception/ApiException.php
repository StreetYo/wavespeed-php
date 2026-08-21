<?php

declare(strict_types=1);

namespace WaveSpeed\Exception;

/**
 * The API answered, but not with the result we asked for.
 *
 * `statusCode` is the HTTP status when the failure was an HTTP-level one, and
 * null when the response was HTTP 200 but reported a failure in its payload.
 */
class ApiException extends \RuntimeException implements WaveSpeedException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $responseBody = null,
        public readonly ?string $taskId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
