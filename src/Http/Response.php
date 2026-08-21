<?php

declare(strict_types=1);

namespace WaveSpeed\Http;

use WaveSpeed\Exception\ApiException;

/**
 * A received HTTP response.
 */
final class Response
{
    /**
     * @param array<string, string> $headers Response headers, keyed by lowercased name.
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Decode the body as a JSON object.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException If the body is not a JSON object.
     */
    public function json(): array
    {
        try {
            $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiException(
                'Failed to decode API response as JSON: ' . $e->getMessage(),
                statusCode: $this->statusCode,
                responseBody: $this->body,
                previous: $e,
            );
        }

        if (!is_array($decoded)) {
            throw new ApiException(
                'Unexpected API response: expected a JSON object, got ' . get_debug_type($decoded),
                statusCode: $this->statusCode,
                responseBody: $this->body,
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
