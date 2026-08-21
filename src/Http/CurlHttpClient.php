<?php

declare(strict_types=1);

namespace WaveSpeed\Http;

use WaveSpeed\Exception\ConnectionException;

/**
 * Default transport: one cURL handle per request.
 *
 * Stream bodies are uploaded with cURL's own read callback rather than being
 * read into a string, so uploading a multi-gigabyte video costs no extra
 * memory.
 */
final class CurlHttpClient implements HttpClient
{
    /**
     * @param array<int, mixed> $curlOptions Extra cURL options merged last, e.g. proxy settings.
     */
    public function __construct(
        private readonly array $curlOptions = [],
    ) {
    }

    public function send(Request $request): Response
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new ConnectionException('Failed to initialize a cURL handle.');
        }

        $responseHeaders = [];

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => self::toMilliseconds($request->connectTimeout),
            CURLOPT_TIMEOUT_MS => self::toMilliseconds($request->timeout),
            CURLOPT_HTTPHEADER => $request->headerLines(),
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];

        $method = strtoupper($request->method);
        $body = $request->body;

        if (is_resource($body)) {
            // Stream the payload: cURL pulls from the handle as the socket drains.
            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_INFILE] = $body;
            $size = self::streamSize($body);
            if ($size !== null) {
                // PHP exposes only CURLOPT_INFILESIZE, which takes a PHP int —
                // 64-bit on any modern build, so large files are fine.
                $options[CURLOPT_INFILESIZE] = $size;
            }
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        } elseif (is_string($body)) {
            $options[CURLOPT_POSTFIELDS] = $body;
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        } elseif ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }

        curl_setopt_array($handle, $options + $this->curlOptions);

        $raw = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $errorMessage = curl_error($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($raw === false || $errorNumber !== 0) {
            $isTimeout = in_array($errorNumber, [CURLE_OPERATION_TIMEOUTED, 28], true);

            throw new ConnectionException(
                sprintf(
                    '%s %s failed: cURL error %d: %s',
                    $method,
                    $request->url,
                    $errorNumber,
                    $errorMessage !== '' ? $errorMessage : 'unknown error',
                ),
                isTimeout: $isTimeout,
            );
        }

        return new Response($statusCode, is_string($raw) ? $raw : '', $responseHeaders);
    }

    /**
     * cURL takes millisecond timeouts; sub-millisecond values would round to
     * "no timeout", so they are clamped to 1ms instead.
     */
    private static function toMilliseconds(float $seconds): int
    {
        if ($seconds <= 0.0) {
            return 0;
        }

        return max(1, (int) round($seconds * 1000));
    }

    /**
     * @param resource $stream
     */
    private static function streamSize($stream): ?int
    {
        $stats = @fstat($stream);
        if (!is_array($stats) || !isset($stats['size'])) {
            return null;
        }

        $position = @ftell($stream);
        $size = (int) $stats['size'] - (is_int($position) ? $position : 0);

        return $size >= 0 ? $size : null;
    }
}
