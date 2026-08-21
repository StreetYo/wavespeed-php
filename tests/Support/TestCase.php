<?php

declare(strict_types=1);

namespace WaveSpeed\Tests\Support;

use PHPUnit\Framework\TestCase as BaseTestCase;
use WaveSpeed\Client;
use WaveSpeed\Config;
use WaveSpeed\WaveSpeed;

/**
 * Shared base: isolates the global `Config` and the default client so tests
 * cannot leak state into each other, and keeps the environment clean.
 */
abstract class TestCase extends BaseTestCase
{
    protected MockHttpClient $http;
    protected FakeClock $clock;
    protected RecordingLogger $logger;

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberEnv('WAVESPEED_API_KEY');
        $this->rememberEnv('WAVESPEED_CLIENT_NAME');
        putenv('WAVESPEED_API_KEY');
        putenv('WAVESPEED_CLIENT_NAME');

        Config::reset();
        WaveSpeed::setClient(null);

        $this->http = new MockHttpClient();
        $this->clock = new FakeClock();
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        $this->originalEnv = [];

        Config::reset();
        WaveSpeed::setClient(null);

        parent::tearDown();
    }

    /**
     * A client wired to the mock transport, fake clock, and recording logger.
     */
    protected function client(
        ?string $apiKey = 'test-key',
        ?int $maxRetries = null,
        ?int $maxConnectionRetries = null,
        ?float $retryInterval = null,
        ?string $baseUrl = null,
        ?string $clientName = null,
    ): Client {
        return new Client(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            maxRetries: $maxRetries,
            maxConnectionRetries: $maxConnectionRetries,
            retryInterval: $retryInterval,
            clientName: $clientName,
            http: $this->http,
            clock: $this->clock,
            logger: $this->logger,
        );
    }

    /**
     * Envelope shape the API uses for a prediction.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected static function prediction(string $status, array $overrides = []): array
    {
        return ['data' => array_merge([
            'id' => 'task-123',
            'status' => $status,
        ], $overrides)];
    }

    private function rememberEnv(string $name): void
    {
        $this->originalEnv[$name] = getenv($name);
    }
}
