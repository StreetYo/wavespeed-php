<?php

declare(strict_types=1);

namespace WaveSpeed\Exception;

/**
 * The HTTP request never produced a response: DNS failure, refused
 * connection, TLS error, or a transport-level timeout.
 *
 * These are transient by nature and safe to retry for idempotent requests.
 */
class ConnectionException extends \RuntimeException implements WaveSpeedException
{
    public function __construct(
        string $message,
        public readonly bool $isTimeout = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
