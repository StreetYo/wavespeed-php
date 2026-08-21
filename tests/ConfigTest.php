<?php

declare(strict_types=1);

namespace WaveSpeed\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use WaveSpeed\Config;
use WaveSpeed\Exception\ConfigurationException;
use WaveSpeed\Tests\Support\TestCase;

#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    public function testShippedDefaults(): void
    {
        self::assertNull(Config::apiKey());
        self::assertSame('https://api.wavespeed.ai', Config::baseUrl());
        self::assertSame(10.0, Config::connectionTimeout());
        self::assertSame(36000.0, Config::timeout());
        self::assertSame(0, Config::maxRetries());
        self::assertSame(5, Config::maxConnectionRetries());
        self::assertSame(1.0, Config::retryInterval());
    }

    public function testEveryDefaultIsExposedThroughAll(): void
    {
        self::assertSame(array_keys(Config::DEFAULTS), array_keys(Config::all()));
    }

    public function testApiKeyIsSeededFromTheEnvironment(): void
    {
        putenv('WAVESPEED_API_KEY=env-key');
        Config::reset();

        self::assertSame('env-key', Config::apiKey());
    }

    public function testAnEmptyEnvironmentApiKeyIsIgnored(): void
    {
        putenv('WAVESPEED_API_KEY=');
        Config::reset();

        self::assertNull(Config::apiKey());
    }

    public function testSetAndGetRoundTrip(): void
    {
        Config::set(Config::API_KEY, 'set-key');

        self::assertSame('set-key', Config::get(Config::API_KEY));
        self::assertSame('set-key', Config::apiKey());
    }

    public function testApplyWritesSeveralKeys(): void
    {
        Config::apply([
            Config::MAX_RETRIES => 4,
            Config::RETRY_INTERVAL => 0.25,
        ]);

        self::assertSame(4, Config::maxRetries());
        self::assertSame(0.25, Config::retryInterval());
    }

    public function testReadingAnUnknownKeyThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Unknown configuration key "nope".');

        Config::get('nope');
    }

    public function testWritingAnUnknownKeyThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Unknown configuration key "nope".');

        Config::set('nope', 1);
    }

    public function testPatchRestoresPreviousValues(): void
    {
        Config::set(Config::MAX_RETRIES, 2);

        $seen = Config::patch([Config::MAX_RETRIES => 9], static fn (): int => Config::maxRetries());

        self::assertSame(9, $seen);
        self::assertSame(2, Config::maxRetries());
    }

    public function testPatchRestoresEvenWhenTheCallbackThrows(): void
    {
        Config::set(Config::BASE_URL, 'https://original.example.com');

        // Throws only if the temporary value really was in effect, so this
        // covers both halves of patch(): applying and restoring. DomainException
        // is a type PHPUnit never throws, so the catch cannot swallow a failed
        // assertion.
        $callback = static function (): void {
            if (Config::baseUrl() === 'https://temporary.example.com') {
                throw new \DomainException('boom');
            }
        };

        try {
            Config::patch([Config::BASE_URL => 'https://temporary.example.com'], $callback);
            self::fail('Expected the callback exception to propagate.');
        } catch (\DomainException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame('https://original.example.com', Config::baseUrl());
    }

    public function testPatchRejectsAnUnknownKeyAndLeavesSettingsIntact(): void
    {
        Config::set(Config::MAX_RETRIES, 2);

        try {
            Config::patch(['nope' => 1], static fn (): int => 0);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException) {
            // expected
        }

        self::assertSame(2, Config::maxRetries());
    }

    public function testResetReturnsToDefaults(): void
    {
        Config::set(Config::TIMEOUT, 1.0);
        Config::reset();

        self::assertSame(36000.0, Config::timeout());
    }
}
