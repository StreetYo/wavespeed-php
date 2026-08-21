<?php

declare(strict_types=1);

namespace WaveSpeed\Http;

/**
 * An outbound HTTP request, fully described so that any transport can send it.
 *
 * `body` is a string for JSON payloads and a stream resource for file
 * uploads, which lets large files go out without being buffered in memory.
 */
final class Request
{
    /**
     * @param array<string, string> $headers
     * @param string|resource|null  $body
     * @param float                 $connectTimeout Seconds allowed for establishing the connection.
     * @param float                 $timeout        Seconds allowed for the whole request.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly mixed $body = null,
        public readonly float $connectTimeout = 10.0,
        public readonly float $timeout = 36000.0,
    ) {
    }

    /**
     * Headers rendered as the `Name: value` lines a transport expects.
     *
     * @return list<string>
     */
    public function headerLines(): array
    {
        $lines = [];
        foreach ($this->headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }
}
