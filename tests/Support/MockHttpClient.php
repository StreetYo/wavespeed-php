<?php

declare(strict_types=1);

namespace WaveSpeed\Tests\Support;

use WaveSpeed\Exception\ConnectionException;
use WaveSpeed\Http\HttpClient;
use WaveSpeed\Http\Request;
use WaveSpeed\Http\Response;

/**
 * Scripted transport: hand it the responses a test needs, then assert on the
 * requests it recorded. Nothing here touches the network.
 */
final class MockHttpClient implements HttpClient
{
    /** @var list<Response|\Throwable|callable(Request): Response> */
    private array $queue = [];

    /** @var list<Request> */
    public array $requests = [];

    /**
     * @param list<Response|\Throwable|callable(Request): Response> $queue
     */
    public function __construct(array $queue = [])
    {
        $this->queue = $queue;
    }

    /**
     * Queue a JSON response.
     *
     * @param array<string, mixed> $payload
     */
    public function pushJson(array $payload, int $statusCode = 200): self
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->queue[] = new Response($statusCode, $body, ['content-type' => 'application/json']);

        return $this;
    }

    public function pushBody(string $body, int $statusCode = 200): self
    {
        $this->queue[] = new Response($statusCode, $body);

        return $this;
    }

    /**
     * Queue a transport failure — no response at all.
     */
    public function pushConnectionError(string $message = 'connection refused', bool $isTimeout = false): self
    {
        $this->queue[] = new ConnectionException($message, isTimeout: $isTimeout);

        return $this;
    }

    /**
     * Queue a response computed from the request itself.
     *
     * @param callable(Request): Response $handler
     */
    public function pushHandler(callable $handler): self
    {
        $this->queue[] = $handler;

        return $this;
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;

        if ($this->queue === []) {
            throw new \LogicException(sprintf(
                'MockHttpClient ran out of queued responses at request #%d: %s %s',
                count($this->requests),
                $request->method,
                $request->url,
            ));
        }

        $next = array_shift($this->queue);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        if ($next instanceof Response) {
            return $next;
        }

        return $next($request);
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    public function lastRequest(): ?Request
    {
        return $this->requests === [] ? null : $this->requests[count($this->requests) - 1];
    }

    public function requestAt(int $index): Request
    {
        if (!isset($this->requests[$index])) {
            throw new \OutOfBoundsException(sprintf('No request recorded at index %d.', $index));
        }

        return $this->requests[$index];
    }

    /**
     * Decoded JSON body of a recorded request.
     *
     * @return array<string, mixed>
     */
    public function bodyAt(int $index): array
    {
        $body = $this->requestAt($index)->body;
        if (!is_string($body)) {
            throw new \LogicException(sprintf('Request #%d does not carry a string body.', $index));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * How many queued responses were never used.
     */
    public function remaining(): int
    {
        return count($this->queue);
    }
}
