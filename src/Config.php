<?php

declare(strict_types=1);

namespace WaveSpeed;

use WaveSpeed\Exception\ConfigurationException;

/**
 * Process-wide defaults, the PHP counterpart of `wavespeed.config.api`.
 *
 * Every `Client` reads these at construction time, so setting them once during
 * bootstrap configures the whole application:
 *
 *     Config::set(Config::API_KEY, $key);
 *
 * `timeout` is the one setting read live on each call rather than captured,
 * matching the Python SDK.
 */
final class Config
{
    /** WaveSpeed API key. Seeded from WAVESPEED_API_KEY. */
    public const API_KEY = 'api_key';

    /** API base URL. */
    public const BASE_URL = 'base_url';

    /** Connection timeout in seconds. */
    public const CONNECTION_TIMEOUT = 'connection_timeout';

    /** Total API call timeout in seconds. */
    public const TIMEOUT = 'timeout';

    /** Task-level retries: replacement tasks after a confirmed terminal failure. */
    public const MAX_RETRIES = 'max_retries';

    /** Retries for idempotent result-query GETs. Submission POSTs are sent at most once. */
    public const MAX_CONNECTION_RETRIES = 'max_connection_retries';

    /** Base interval between retries in seconds (actual delay = interval * attempt). */
    public const RETRY_INTERVAL = 'retry_interval';

    /**
     * Shipped defaults. Mirrors the Python SDK's `config.api`.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        self::API_KEY => null,
        self::BASE_URL => 'https://api.wavespeed.ai',
        self::CONNECTION_TIMEOUT => 10.0,
        self::TIMEOUT => 36000.0,
        self::MAX_RETRIES => 0,
        self::MAX_CONNECTION_RETRIES => 5,
        self::RETRY_INTERVAL => 1.0,
    ];

    /** @var array<string, mixed>|null */
    private static ?array $values = null;

    private function __construct()
    {
    }

    /**
     * All current settings.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return self::values();
    }

    /**
     * Read one setting.
     *
     * @throws ConfigurationException If the key does not exist.
     */
    public static function get(string $key): mixed
    {
        $values = self::values();
        if (!array_key_exists($key, $values)) {
            throw new ConfigurationException(sprintf('Unknown configuration key "%s".', $key));
        }

        return $values[$key];
    }

    /**
     * Write one setting.
     *
     * @throws ConfigurationException If the key does not exist.
     */
    public static function set(string $key, mixed $value): void
    {
        self::values();
        if (!array_key_exists($key, self::DEFAULTS)) {
            throw new ConfigurationException(sprintf('Unknown configuration key "%s".', $key));
        }

        self::$values[$key] = $value;
    }

    /**
     * Write several settings at once.
     *
     * @param array<string, mixed> $changes
     *
     * @throws ConfigurationException If any key does not exist.
     */
    public static function apply(array $changes): void
    {
        foreach ($changes as $key => $value) {
            self::set($key, $value);
        }
    }

    /**
     * Run a callback with settings temporarily changed, then restore them —
     * the equivalent of the Python SDK's `config.patch(...)` context manager.
     *
     * Restoration happens even if the callback throws.
     *
     * @template T
     *
     * @param array<string, mixed> $changes
     * @param callable(): T        $callback
     *
     * @return T
     */
    public static function patch(array $changes, callable $callback): mixed
    {
        $previous = self::values();

        try {
            // Inside the try so a rejected key cannot leave a partial write
            // behind either.
            self::apply($changes);

            return $callback();
        } finally {
            self::$values = $previous;
        }
    }

    /**
     * Restore the shipped defaults, re-reading the environment.
     */
    public static function reset(): void
    {
        self::$values = null;
    }

    public static function apiKey(): ?string
    {
        $value = self::get(self::API_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function baseUrl(): string
    {
        return (string) self::get(self::BASE_URL);
    }

    public static function connectionTimeout(): float
    {
        return (float) self::get(self::CONNECTION_TIMEOUT);
    }

    public static function timeout(): float
    {
        return (float) self::get(self::TIMEOUT);
    }

    public static function maxRetries(): int
    {
        return (int) self::get(self::MAX_RETRIES);
    }

    public static function maxConnectionRetries(): int
    {
        return (int) self::get(self::MAX_CONNECTION_RETRIES);
    }

    public static function retryInterval(): float
    {
        return (float) self::get(self::RETRY_INTERVAL);
    }

    /**
     * @return array<string, mixed>
     */
    private static function values(): array
    {
        if (self::$values === null) {
            self::$values = self::DEFAULTS;

            $apiKey = getenv('WAVESPEED_API_KEY');
            if (is_string($apiKey) && $apiKey !== '') {
                self::$values[self::API_KEY] = $apiKey;
            }
        }

        return self::$values;
    }
}
