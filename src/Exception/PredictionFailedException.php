<?php

declare(strict_types=1);

namespace WaveSpeed\Exception;

/**
 * The prediction reached a terminal state without producing outputs
 * (`failed`, `cancelled`, or a server-side `timeout`).
 *
 * This is a decision by the platform about this task, not a transport
 * problem, so the SDK never retries it.
 */
class PredictionFailedException extends ApiException
{
    public function __construct(
        string $message,
        public readonly ?string $predictionStatus = null,
        ?string $taskId = null,
        ?string $responseBody = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, null, $responseBody, $taskId, $previous);
    }
}
