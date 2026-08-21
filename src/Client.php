<?php

declare(strict_types=1);

namespace WaveSpeed;

use WaveSpeed\Exception\ApiException;
use WaveSpeed\Exception\ConfigurationException;
use WaveSpeed\Exception\ConnectionException;
use WaveSpeed\Exception\FileNotFoundException;
use WaveSpeed\Exception\PredictionFailedException;
use WaveSpeed\Exception\SubmissionException;
use WaveSpeed\Exception\SyncModeTimeoutException;
use WaveSpeed\Exception\TimeoutException;
use WaveSpeed\Http\CurlHttpClient;
use WaveSpeed\Http\HttpClient;
use WaveSpeed\Http\Request;
use WaveSpeed\Internal\MimeTypes;
use WaveSpeed\Internal\Paths;
use WaveSpeed\Support\Clock;
use WaveSpeed\Support\Logger;
use WaveSpeed\Support\NullLogger;
use WaveSpeed\Support\SystemClock;

/**
 * WaveSpeed API client.
 *
 * Submits jobs to the hosted WaveSpeed API, polls for results, and uploads
 * media. Synchronous, and stateless apart from its configuration, so a single
 * instance is safe to reuse for the lifetime of a request or worker.
 *
 *     $client = new Client(apiKey: 'your-api-key');
 *     $output = $client->run('wavespeed-ai/z-image/turbo', ['prompt' => 'Cat']);
 *     echo $output['outputs'][0];
 */
class Client
{
    /** Channel-attribution name sent as X-Client-Name unless overridden. */
    public const DEFAULT_CLIENT_NAME = 'wavespeed-php';

    /** HTTP statuses worth retrying for idempotent requests. */
    private const RETRYABLE_STATUS_CODES = [429, 500, 502, 503, 504];

    /** API error code for "sync mode gave up waiting". */
    private const SYNC_MODE_TIMEOUT_CODE = 5004;

    /** Cap on buffering a non-seekable upload stream in memory: 200 MiB. */
    private const MAX_BUFFERED_UPLOAD_BYTES = 200 * 1024 * 1024;

    public readonly ?string $apiKey;
    public readonly string $baseUrl;
    public readonly float $connectionTimeout;
    public readonly int $maxRetries;
    public readonly int $maxConnectionRetries;
    public readonly float $retryInterval;
    public readonly string $clientName;

    private readonly HttpClient $http;
    private readonly Clock $clock;
    private readonly Logger $logger;

    /**
     * Every argument falls back to the corresponding `Config` value, so the
     * common case is `new Client()` with the API key in the environment.
     *
     * @param string|null     $apiKey               API key. Defaults to `Config::API_KEY` (seeded from WAVESPEED_API_KEY).
     * @param string|null     $baseUrl              API base URL.
     * @param float|null      $connectionTimeout    Seconds allowed for establishing a connection.
     * @param int|null        $maxRetries           Task-level retries — replacement tasks after a confirmed terminal failure.
     * @param int|null        $maxConnectionRetries Retries for result-query GETs. Submission POSTs are never retried.
     * @param float|null      $retryInterval        Base delay between retries; the actual delay grows with the attempt number.
     * @param string|null     $clientName           Channel attribution. WAVESPEED_CLIENT_NAME wins over this argument.
     * @param HttpClient|null $http                 Transport. Defaults to cURL.
     * @param Clock|null      $clock                Time source used for polling and backoff.
     * @param Logger|null     $logger               Retry/polling diagnostics. Silent by default.
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?float $connectionTimeout = null,
        ?int $maxRetries = null,
        ?int $maxConnectionRetries = null,
        ?float $retryInterval = null,
        ?string $clientName = null,
        ?HttpClient $http = null,
        ?Clock $clock = null,
        ?Logger $logger = null,
    ) {
        $this->apiKey = $apiKey ?? Config::apiKey();
        $this->baseUrl = rtrim($baseUrl ?? Config::baseUrl(), '/');
        $this->connectionTimeout = $connectionTimeout ?? Config::connectionTimeout();
        $this->maxRetries = $maxRetries ?? Config::maxRetries();
        $this->maxConnectionRetries = $maxConnectionRetries ?? Config::maxConnectionRetries();
        $this->retryInterval = $retryInterval ?? Config::retryInterval();

        $envClientName = getenv('WAVESPEED_CLIENT_NAME');
        $this->clientName = is_string($envClientName) && $envClientName !== ''
            ? $envClientName
            : ($clientName ?? self::DEFAULT_CLIENT_NAME);

        $this->http = $http ?? new CurlHttpClient();
        $this->clock = $clock ?? new SystemClock();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Run a model and wait for its output.
     *
     * @param string                    $model          Model identifier, e.g. "wavespeed-ai/z-image/turbo".
     * @param array<string, mixed>|null $input          Model input parameters.
     * @param float|null                $timeout        Maximum seconds to wait for completion. Null means no local
     *                                                  limit; each HTTP request still uses `Config::TIMEOUT`.
     * @param float                     $pollInterval   Seconds between status checks.
     * @param bool                      $enableSyncMode Ask the API to return the result in the submit response.
     * @param int|null                  $maxRetries     Task-level retries for this call.
     *
     * @return array{outputs: list<mixed>}
     *
     * @throws ConfigurationException           If no API key is configured.
     * @throws SubmissionException              If submission failed (never retried automatically).
     * @throws PredictionFailedException        If the task reached a terminal failure.
     * @throws SyncModeTimeoutException         If sync mode gave up while the task kept running.
     * @throws TimeoutException                 If the local wait budget ran out.
     * @throws ApiException|ConnectionException On other API or transport failures.
     */
    public function run(
        string $model,
        ?array $input = null,
        ?float $timeout = null,
        float $pollInterval = 1.0,
        bool $enableSyncMode = false,
        ?int $maxRetries = null,
    ): array {
        $taskRetries = max(0, $maxRetries ?? $this->maxRetries);
        $lastError = null;

        for ($attempt = 0; $attempt <= $taskRetries; $attempt++) {
            try {
                [$requestId, $syncResult] = $this->submit($model, $input, $enableSyncMode, $timeout);

                if ($enableSyncMode) {
                    $data = self::dataOf($syncResult);
                    if (self::statusOf($data) !== PredictionStatus::Completed->value) {
                        throw self::syncModeException($data, $syncResult);
                    }

                    return ['outputs' => self::outputsOf($data)];
                }

                /** @var string $requestId */
                return $this->wait($requestId, $timeout, $pollInterval);
            } catch (\Throwable $e) {
                $lastError = $e;

                if (!$this->isRetryableError($e) || $attempt >= $taskRetries) {
                    throw $e;
                }

                $this->logRetry($e, $attempt, $taskRetries);
            }
        }

        throw $lastError ?? new ApiException(sprintf('All %d attempts failed', $taskRetries + 1));
    }

    /**
     * Run a model and describe the outcome instead of throwing.
     *
     * The counterpart of the Python SDK's `run_no_throw` and the JavaScript
     * SDK's `runNoThrow`: failures — including a server-side sync-mode timeout
     * — come back in the returned array, with the task ID whenever it is known
     * so the result can be collected later.
     *
     * `status` is "completed", "failed", or "processing"; the last means the
     * task is still running server-side and `outputs` is null.
     *
     * @param array<string, mixed>|null $input
     *
     * @return array{status: string, outputs: list<mixed>|null, task_id: string, error: string|null}
     */
    public function runNoThrow(
        string $model,
        ?array $input = null,
        ?float $timeout = null,
        float $pollInterval = 1.0,
        bool $enableSyncMode = false,
        ?int $maxRetries = null,
    ): array {
        $taskRetries = max(0, $maxRetries ?? $this->maxRetries);

        for ($attempt = 0; $attempt <= $taskRetries; $attempt++) {
            try {
                [$requestId, $syncResult] = $this->submit($model, $input, $enableSyncMode, $timeout);

                if ($enableSyncMode) {
                    $data = self::dataOf($syncResult);

                    if (self::statusOf($data) !== PredictionStatus::Completed->value) {
                        $error = self::syncModeException($data, $syncResult);

                        return [
                            'status' => $error instanceof SyncModeTimeoutException
                                ? PredictionStatus::Processing->value
                                : PredictionStatus::Failed->value,
                            'outputs' => null,
                            'task_id' => self::taskIdOf($data),
                            'error' => $error->getMessage(),
                        ];
                    }

                    return [
                        'status' => PredictionStatus::Completed->value,
                        'outputs' => self::outputsOf($data),
                        'task_id' => self::taskIdOf($data),
                        'error' => null,
                    ];
                }

                /** @var string $requestId */
                $result = $this->wait($requestId, $timeout, $pollInterval);

                return [
                    'status' => PredictionStatus::Completed->value,
                    'outputs' => $result['outputs'],
                    'task_id' => $requestId,
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                if ($this->isRetryableError($e) && $attempt < $taskRetries) {
                    $this->logRetry($e, $attempt, $taskRetries);

                    continue;
                }

                $isSyncTimeout = $e instanceof SyncModeTimeoutException
                    || str_contains(strtolower($e->getMessage()), 'sync mode timed out');

                return [
                    'status' => $isSyncTimeout
                        ? PredictionStatus::Processing->value
                        : PredictionStatus::Failed->value,
                    'outputs' => null,
                    'task_id' => self::taskIdFromError($e),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'status' => PredictionStatus::Failed->value,
            'outputs' => null,
            'task_id' => 'unknown',
            'error' => sprintf('All %d attempts failed', $taskRetries + 1),
        ];
    }

    /**
     * Fetch a prediction's current state by id.
     *
     * This is how a task recovers from a local timeout: the job keeps running
     * server-side, and this returns whatever state it has reached.
     *
     * Transient failures are retried up to `maxConnectionRetries` times — the
     * GET is idempotent, so replaying it costs nothing.
     *
     * @return array<string, mixed> The full API response, including `data.status` and `data.outputs`.
     *
     * @throws ApiException|ConnectionException|ConfigurationException
     */
    public function getResult(string $requestId, ?float $timeout = null): array
    {
        $url = $this->baseUrl . '/api/v3/predictions/' . rawurlencode($requestId) . '/result';
        $requestTimeout = $timeout ?? Config::timeout();
        $attempts = $this->maxConnectionRetries + 1;
        $lastError = null;

        for ($retry = 0; $retry < $attempts; $retry++) {
            try {
                $response = $this->http->send(new Request(
                    method: 'GET',
                    url: $url,
                    headers: $this->headers(),
                    connectTimeout: $this->resolveConnectTimeout($requestTimeout),
                    timeout: $requestTimeout,
                ));

                if ($response->statusCode !== 200) {
                    $error = new ApiException(
                        sprintf(
                            'Failed to get result for task %s: HTTP %d: %s',
                            $requestId,
                            $response->statusCode,
                            $response->body,
                        ),
                        statusCode: $response->statusCode,
                        responseBody: $response->body,
                        taskId: $requestId,
                    );

                    if (!self::isRetryableStatus($response->statusCode) || $retry >= $attempts - 1) {
                        throw $error;
                    }

                    $lastError = $error;
                    $delay = $this->retryInterval * ($retry + 1);
                    $this->logger->log(sprintf(
                        'Server error (HTTP %d) getting result on attempt %d/%d, retrying in %s seconds...',
                        $response->statusCode,
                        $retry + 1,
                        $attempts,
                        self::formatSeconds($delay),
                    ));
                    $this->clock->sleep($delay);

                    continue;
                }

                return $response->json();
            } catch (ConnectionException $e) {
                $lastError = $e;
                $this->logger->log(sprintf(
                    'Connection error getting result on attempt %d/%d: %s',
                    $retry + 1,
                    $attempts,
                    $e->getMessage(),
                ));

                if ($retry >= $attempts - 1) {
                    throw new ConnectionException(
                        sprintf('Failed to get result for task %s after %d attempts', $requestId, $attempts),
                        isTimeout: $e->isTimeout,
                        previous: $e,
                    );
                }

                $delay = $this->retryInterval * ($retry + 1);
                $this->logger->log(sprintf('Retrying in %s seconds...', self::formatSeconds($delay)));
                $this->clock->sleep($delay);
            }
        }

        throw $lastError ?? new ApiException(
            sprintf('Failed to get result for task %s after %d attempts', $requestId, $attempts),
            taskId: $requestId,
        );
    }

    /**
     * Upload a file and get back a URL usable as model input.
     *
     * @param string|resource $file    Path to a file, or an open readable stream.
     * @param float|null      $timeout Total seconds allowed for each of the two HTTP calls.
     *
     * @return string Download URL of the uploaded file.
     *
     * @throws ConfigurationException|FileNotFoundException|ApiException|ConnectionException
     */
    public function upload(mixed $file, ?float $timeout = null): string
    {
        $headers = $this->headers();
        $requestTimeout = $timeout ?? Config::timeout();
        $connectTimeout = $this->resolveConnectTimeout($requestTimeout);

        [$stream, $filename, $size, $shouldClose] = $this->openUpload($file);

        try {
            $payload = ['filename' => $filename, 'size' => $size];
            $contentType = MimeTypes::guess($filename);
            if ($contentType !== null) {
                $payload['content_type'] = $contentType;
            }

            $ticketResponse = $this->http->send(new Request(
                method: 'POST',
                url: $this->baseUrl . '/api/v3/media/uploads',
                headers: $headers,
                body: self::encodeJson($payload),
                connectTimeout: $connectTimeout,
                timeout: $requestTimeout,
            ));

            if ($ticketResponse->statusCode !== 200) {
                throw new ApiException(
                    sprintf(
                        'Failed to create upload: HTTP %d: %s',
                        $ticketResponse->statusCode,
                        $ticketResponse->body,
                    ),
                    statusCode: $ticketResponse->statusCode,
                    responseBody: $ticketResponse->body,
                );
            }

            $result = $ticketResponse->json();
            if (($result['code'] ?? null) !== 200) {
                $message = $result['message'] ?? null;

                throw new ApiException(
                    'Upload failed: ' . (is_string($message) && $message !== '' ? $message : 'Unknown error'),
                    responseBody: $ticketResponse->body,
                );
            }

            $ticket = is_array($result['data'] ?? null) ? $result['data'] : [];
            $instruction = is_array($ticket['upload'] ?? null) ? $ticket['upload'] : [];
            $method = is_string($instruction['method'] ?? null) ? strtoupper($instruction['method']) : '';
            $uploadUrl = $instruction['url'] ?? null;

            if ($method !== 'PUT' || !is_string($uploadUrl) || $uploadUrl === '') {
                throw new ApiException(
                    'Upload failed: invalid upload instruction',
                    responseBody: $ticketResponse->body,
                );
            }

            /** @var array<string, string> $uploadHeaders */
            $uploadHeaders = is_array($instruction['headers'] ?? null) ? $instruction['headers'] : [];

            $uploadResponse = $this->http->send(new Request(
                method: 'PUT',
                url: $uploadUrl,
                headers: $uploadHeaders,
                body: $stream,
                connectTimeout: $connectTimeout,
                timeout: $requestTimeout,
            ));

            if (!$uploadResponse->isSuccessful()) {
                throw new ApiException(
                    sprintf(
                        'Failed to upload file: HTTP %d: %s',
                        $uploadResponse->statusCode,
                        $uploadResponse->body,
                    ),
                    statusCode: $uploadResponse->statusCode,
                    responseBody: $uploadResponse->body,
                );
            }

            $downloadUrl = $ticket['download_url'] ?? null;
            if (!is_string($downloadUrl) || $downloadUrl === '') {
                throw new ApiException(
                    'Upload failed: no download_url in response',
                    responseBody: $ticketResponse->body,
                );
            }

            return $downloadUrl;
        } finally {
            if ($shouldClose && is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Submit a prediction request.
     *
     * Sent at most once: a submission POST is not idempotent, so a failure
     * without a response becomes a `SubmissionException` rather than a retry.
     *
     * @param array<string, mixed>|null $input
     *
     * @return array{0: string|null, 1: array<string, mixed>|null} [requestId, syncResult] — exactly one is set.
     *
     * @throws SubmissionException|ConfigurationException
     */
    private function submit(
        string $model,
        ?array $input,
        bool $enableSyncMode,
        ?float $timeout,
    ): array {
        $body = $input ?? [];
        if ($enableSyncMode) {
            $body['enable_sync_mode'] = true;
        }

        $requestTimeout = $timeout ?? Config::timeout();

        try {
            $response = $this->http->send(new Request(
                method: 'POST',
                url: $this->baseUrl . '/api/v3/' . ltrim($model, '/'),
                headers: $this->headers(),
                body: self::encodeJson($body),
                connectTimeout: $this->resolveConnectTimeout($requestTimeout),
                timeout: $requestTimeout,
            ));
        } catch (ConnectionException $e) {
            throw new SubmissionException(
                'Prediction submission did not return a response. The task may already '
                . 'have been created, so the SDK will not retry the POST automatically.',
                previous: $e,
            );
        }

        if ($response->statusCode !== 200) {
            throw new SubmissionException(
                sprintf('Failed to submit prediction: HTTP %d: %s', $response->statusCode, $response->body),
                statusCode: $response->statusCode,
                responseBody: $response->body,
            );
        }

        $result = $response->json();

        if ($enableSyncMode) {
            return [null, $result];
        }

        $requestId = self::dataOf($result)['id'] ?? null;
        if (!is_string($requestId) || $requestId === '') {
            throw new SubmissionException(
                'No request ID in response: ' . $response->body,
                statusCode: $response->statusCode,
                responseBody: $response->body,
            );
        }

        return [$requestId, null];
    }

    /**
     * Poll until the prediction reaches a terminal state.
     *
     * @return array{outputs: list<mixed>}
     *
     * @throws PredictionFailedException|TimeoutException|ApiException|ConnectionException
     */
    private function wait(string $requestId, ?float $timeout, float $pollInterval): array
    {
        $start = $this->clock->now();

        while (true) {
            if ($timeout !== null && $this->clock->now() - $start >= $timeout) {
                throw new TimeoutException(
                    sprintf(
                        'Prediction timed out after %s seconds (task_id: %s)',
                        self::formatSeconds($timeout),
                        $requestId,
                    ),
                    taskId: $requestId,
                );
            }

            $data = self::dataOf($this->getResult($requestId, $timeout));
            $status = PredictionStatus::tryFrom(self::statusOf($data) ?? '');

            if ($status === PredictionStatus::Completed) {
                return ['outputs' => self::outputsOf($data)];
            }

            if ($status !== null && $status->isFailure()) {
                $error = $data['error'] ?? null;

                throw new PredictionFailedException(
                    sprintf(
                        'Prediction %s (task_id: %s): %s',
                        $status->value,
                        $requestId,
                        is_string($error) && $error !== '' ? $error : 'Unknown error',
                    ),
                    predictionStatus: $status->value,
                    taskId: $requestId,
                );
            }

            $this->clock->sleep($pollInterval);
        }
    }

    /**
     * Request headers, including channel attribution.
     *
     * @return array<string, string>
     *
     * @throws ConfigurationException If no API key is configured.
     */
    private function headers(): array
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new ConfigurationException(
                'API key is required. Set the WAVESPEED_API_KEY environment variable, '
                . 'pass apiKey to the Client constructor, or set Config::API_KEY.',
            );
        }

        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
            'X-Client-Name' => $this->clientName,
            'X-Client-Version' => Version::get(),
            'X-Client-OS' => self::clientOs(),
        ];
    }

    /**
     * Whether an error is worth another attempt at the task level.
     */
    private function isRetryableError(\Throwable $error): bool
    {
        // Submission is ambiguous: the server may already have created the
        // task, so a failed POST is never replayed automatically.
        if ($error instanceof SubmissionException) {
            return false;
        }

        // A terminal verdict about this task, not a transport hiccup. Its
        // message may quote an HTTP status coming from the model, which is why
        // this check precedes the ApiException branch below.
        if ($error instanceof PredictionFailedException || $error instanceof SyncModeTimeoutException) {
            return false;
        }

        if ($error instanceof ConnectionException || $error instanceof TimeoutException) {
            return true;
        }

        if ($error instanceof ApiException) {
            if ($error->statusCode !== null) {
                return self::isRetryableStatus($error->statusCode);
            }

            return preg_match('/HTTP (?:5\d\d|429)/', $error->getMessage()) === 1;
        }

        return false;
    }

    private static function isRetryableStatus(int $statusCode): bool
    {
        return in_array($statusCode, self::RETRYABLE_STATUS_CODES, true);
    }

    private function logRetry(\Throwable $error, int $attempt, int $taskRetries): void
    {
        $this->logger->log(sprintf(
            'Task attempt %d/%d failed: %s',
            $attempt + 1,
            $taskRetries + 1,
            $error->getMessage(),
        ));

        $delay = $this->retryInterval * ($attempt + 1);
        $this->logger->log(sprintf('Retrying in %s seconds...', self::formatSeconds($delay)));
        $this->clock->sleep($delay);
    }

    /**
     * Build the exception for a sync-mode response that is not `completed`.
     *
     * A sync-mode timeout is not a failure — the task is still running — so it
     * gets its own type, carrying the URL to poll.
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $response
     */
    private static function syncModeException(array $data, ?array $response = null): ApiException
    {
        $taskId = self::taskIdOf($data);
        $error = $data['error'] ?? null;
        $error = is_string($error) && $error !== '' ? $error : 'Unknown error';
        $body = $response !== null ? json_encode($response) : null;
        $body = is_string($body) ? $body : null;

        $urls = is_array($data['urls'] ?? null) ? $data['urls'] : [];
        $resultUrl = is_string($urls['get'] ?? null) ? $urls['get'] : null;

        $isSyncTimeout = ($data['code'] ?? null) === self::SYNC_MODE_TIMEOUT_CODE
            || (self::statusOf($data) === PredictionStatus::Processing->value
                && str_contains($error, 'Sync mode timed out'));

        if ($isSyncTimeout) {
            $message = sprintf('Sync mode timed out (task_id: %s): %s', $taskId, $error);
            if ($resultUrl !== null && !str_contains($message, $resultUrl)) {
                $message .= sprintf(' Query the result later at: %s', $resultUrl);
            }

            return new SyncModeTimeoutException(
                $message,
                taskId: $taskId,
                resultUrl: $resultUrl,
                responseBody: $body,
            );
        }

        return new PredictionFailedException(
            sprintf('Prediction failed (task_id: %s): %s', $taskId, $error),
            predictionStatus: self::statusOf($data),
            taskId: $taskId,
            responseBody: $body,
        );
    }

    /**
     * Recover a task ID from an exception: the typed property when the SDK
     * raised it, otherwise the "(task_id: ...)" tail of the message.
     */
    private static function taskIdFromError(\Throwable $error): string
    {
        if ($error instanceof ApiException && $error->taskId !== null) {
            return $error->taskId;
        }

        if ($error instanceof TimeoutException && $error->taskId !== null) {
            return $error->taskId;
        }

        if (preg_match('/task_id:\s*([^)]+)/', $error->getMessage(), $matches) === 1) {
            return trim($matches[1]);
        }

        return 'unknown';
    }

    /**
     * Open the upload source, returning its stream, name, byte size, and
     * whether this SDK owns the handle.
     *
     * @param string|resource $file
     *
     * @return array{0: resource, 1: string, 2: int, 3: bool}
     *
     * @throws FileNotFoundException|ConfigurationException
     */
    private function openUpload(mixed $file): array
    {
        if (is_string($file)) {
            if (!is_file($file)) {
                throw new FileNotFoundException(sprintf('File not found: %s', $file));
            }

            $stream = @fopen($file, 'rb');
            if ($stream === false) {
                throw new FileNotFoundException(sprintf('File is not readable: %s', $file));
            }

            $size = filesize($file);

            return [$stream, Paths::basename($file), is_int($size) ? $size : 0, true];
        }

        if (!is_resource($file)) {
            throw new ConfigurationException(
                'upload() expects a file path or an open stream resource, got ' . get_debug_type($file),
            );
        }

        $uri = stream_get_meta_data($file)['uri'] ?? null;
        $filename = is_string($uri) ? Paths::filenameFromStreamUri($uri) : null;
        $filename ??= 'upload';

        $stats = @fstat($file);
        $position = @ftell($file);

        if (is_array($stats) && isset($stats['size']) && is_int($position)) {
            $size = (int) $stats['size'] - $position;
            if ($size > 0) {
                return [$file, $filename, $size, false];
            }
        }

        // Either not seekable (a pipe, say) or reporting no size: buffer it so
        // the byte count is known before the upload ticket is requested.
        $buffer = fopen('php://temp', 'w+b');
        if ($buffer === false) {
            throw new ConfigurationException('Failed to allocate a temporary buffer for the upload stream.');
        }

        $copied = stream_copy_to_stream($file, $buffer, self::MAX_BUFFERED_UPLOAD_BYTES + 1);
        rewind($buffer);

        return [$buffer, $filename, is_int($copied) ? $copied : 0, true];
    }

    /**
     * Cap the connect timeout by the overall budget: waiting 10s to connect
     * makes no sense inside a 2s request.
     */
    private function resolveConnectTimeout(float $requestTimeout): float
    {
        if ($requestTimeout <= 0.0) {
            return $this->connectionTimeout;
        }

        return min($this->connectionTimeout, $requestTimeout);
    }

    /**
     * OS identifier for the X-Client-OS header, using the same vocabulary as
     * the other WaveSpeed clients.
     */
    private static function clientOs(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'windows',
            'Darwin' => 'darwin',
            'Linux' => 'linux',
            'BSD' => 'bsd',
            'Solaris' => 'solaris',
            default => 'unknown',
        };
    }

    /**
     * Encode a request body, keeping an empty payload a JSON object (`{}`)
     * rather than the empty array PHP would otherwise produce.
     *
     * @param array<string, mixed> $body
     */
    private static function encodeJson(array $body): string
    {
        try {
            return json_encode(
                $body === [] ? new \stdClass() : $body,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException $e) {
            throw new ConfigurationException(
                'Request body could not be encoded as JSON: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * The `data` envelope of an API response.
     *
     * @param array<string, mixed>|null $response
     *
     * @return array<string, mixed>
     */
    private static function dataOf(?array $response): array
    {
        $data = $response['data'] ?? null;

        /** @var array<string, mixed> */
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function statusOf(array $data): ?string
    {
        $status = $data['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<mixed>
     */
    private static function outputsOf(array $data): array
    {
        $outputs = $data['outputs'] ?? null;

        return is_array($outputs) ? array_values($outputs) : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function taskIdOf(array $data): string
    {
        $id = $data['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : 'unknown';
    }

    /**
     * Render a duration without PHP's float noise ("1" and "1.5", not "1.0").
     */
    private static function formatSeconds(float $seconds): string
    {
        if ($seconds === floor($seconds) && abs($seconds) < 1e15) {
            return (string) (int) $seconds;
        }

        return rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.');
    }
}
