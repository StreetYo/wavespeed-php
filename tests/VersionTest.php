<?php

declare(strict_types=1);

namespace WaveSpeed\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WaveSpeed\Version;

#[CoversClass(Version::class)]
final class VersionTest extends TestCase
{
    protected function tearDown(): void
    {
        Version::reset();

        parent::tearDown();
    }

    public function testAVersionIsAlwaysReported(): void
    {
        $version = Version::get();

        self::assertNotSame('', $version);
        // Never a bare tag prefix: the header carries the version itself.
        self::assertStringStartsNotWith('v', $version);
    }

    public function testTheVersionIsMemoized(): void
    {
        self::assertSame(Version::get(), Version::get());
    }

    public function testTheFallbackConstantLooksLikeAVersion(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', Version::VERSION);
    }

    public function testResetRecomputesWithoutChangingTheAnswer(): void
    {
        $first = Version::get();
        Version::reset();

        self::assertSame($first, Version::get());
    }
}
