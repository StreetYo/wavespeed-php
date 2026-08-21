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

/**
 * Static entry point over a lazily created default `Client` — the PHP
 * counterpart of the module-level `wavespeed.run(...)` helpers.
 *
 *     use WaveSpeed\WaveSpeed;
 *
 *     $output = WaveSpeed::run('wavespeed-ai/z-image/turbo', ['prompt' => 'Cat']);
 *     echo $output['outputs'][0];
 *
 * Reach for `new Client(...)` instead when an application needs more than one
 * configuration, or wants the client injected as a dependency.
 */
final class WaveSpeed
{
    private static ?Client $defaultClient = null;

    private function __construct()
    {
    }

    /**
     * The shared default client, built from `Config` on first use.
     */
    public static function client(): Client
    {
        return self::$defaultClient ??= new Client();
    }

    /**
     * Replace the shared client — for injecting a configured or fake one.
     *
     * Passing null discards it, so the next call rebuilds from `Config`.
     */
    public static function setClient(?Client $client): void
    {
        self::$defaultClient = $client;
    }

    /**
     * Run a model and wait for its output.
     *
     * @param array<string, mixed>|null $input
     *
     * @return array{outputs: list<mixed>}
     *
     * @throws ConfigurationException|SubmissionException|PredictionFailedException
     * @throws SyncModeTimeoutException|TimeoutException|ApiException|ConnectionException
     *
     * @see Client::run()
     */
    public static function run(
        string $model,
        ?array $input = null,
        ?float $timeout = null,
        float $pollInterval = 1.0,
        bool $enableSyncMode = false,
        ?int $maxRetries = null,
    ): array {
        return self::client()->run(
            $model,
            $input,
            timeout: $timeout,
            pollInterval: $pollInterval,
            enableSyncMode: $enableSyncMode,
            maxRetries: $maxRetries,
        );
    }

    /**
     * Run a model and describe the outcome instead of throwing.
     *
     * @param array<string, mixed>|null $input
     *
     * @return array{status: string, outputs: list<mixed>|null, task_id: string, error: string|null}
     *
     * @see Client::runNoThrow()
     */
    public static function runNoThrow(
        string $model,
        ?array $input = null,
        ?float $timeout = null,
        float $pollInterval = 1.0,
        bool $enableSyncMode = false,
        ?int $maxRetries = null,
    ): array {
        return self::client()->runNoThrow(
            $model,
            $input,
            timeout: $timeout,
            pollInterval: $pollInterval,
            enableSyncMode: $enableSyncMode,
            maxRetries: $maxRetries,
        );
    }

    /**
     * Fetch a prediction's current state by id — the way to recover a task
     * whose local wait timed out.
     *
     * @param string|null $apiKey Use a one-off client with this key instead of the shared one.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException|ConnectionException|ConfigurationException
     *
     * @see Client::getResult()
     */
    public static function getResult(
        string $requestId,
        ?float $timeout = null,
        ?string $apiKey = null,
    ): array {
        $client = $apiKey !== null ? new Client(apiKey: $apiKey) : self::client();

        return $client->getResult($requestId, $timeout);
    }

    /**
     * Upload a file and get back a URL usable as model input.
     *
     * @param string|resource $file
     *
     * @throws ConfigurationException|FileNotFoundException|ApiException|ConnectionException
     *
     * @see Client::upload()
     */
    public static function upload(mixed $file, ?float $timeout = null): string
    {
        return self::client()->upload($file, $timeout);
    }

    /**
     * The SDK version, as reported in the X-Client-Version header.
     */
    public static function version(): string
    {
        return Version::get();
    }
}
