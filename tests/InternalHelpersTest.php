<?php

declare(strict_types=1);

namespace WaveSpeed\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WaveSpeed\Internal\MimeTypes;
use WaveSpeed\Internal\Paths;
use WaveSpeed\PredictionStatus;

#[CoversClass(MimeTypes::class)]
#[CoversClass(Paths::class)]
#[CoversClass(PredictionStatus::class)]
final class InternalHelpersTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function filenames(): iterable
    {
        yield 'png' => ['sunset.png', 'image/png'];
        yield 'jpeg alias' => ['portrait.JPEG', 'image/jpeg'];
        yield 'mp4' => ['clip.mp4', 'video/mp4'];
        yield 'wav' => ['voice.wav', 'audio/wav'];
        yield 'path is ignored' => ['a/b/c/photo.webp', 'image/webp'];
        yield 'unknown extension' => ['weights.unknownext', null];
        yield 'no extension' => ['upload', null];
    }

    #[DataProvider('filenames')]
    public function testContentTypeIsGuessedFromTheExtension(string $filename, ?string $expected): void
    {
        self::assertSame($expected, MimeTypes::guess($filename));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function paths(): iterable
    {
        yield 'posix' => ['/var/data/sunset.png', 'sunset.png'];
        yield 'windows' => ['C:\\Users\\me\\sunset.png', 'sunset.png'];
        yield 'bare name' => ['sunset.png', 'sunset.png'];
        yield 'trailing slash' => ['/var/data/', 'data'];
    }

    #[DataProvider('paths')]
    public function testBasenameHandlesBothSeparators(string $path, string $expected): void
    {
        self::assertSame($expected, Paths::basename($path));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function streamUris(): iterable
    {
        yield 'posix path' => ['/var/data/sunset.png', 'sunset.png'];
        yield 'file url' => ['file:///var/data/sunset.png', 'sunset.png'];
        yield 'php temp' => ['php://temp', null];
        yield 'php memory' => ['php://memory', null];
        yield 'data uri' => ['data://text/plain;base64,SGk=', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('streamUris')]
    public function testAFilenameIsOnlyDerivedFromRealPaths(string $uri, ?string $expected): void
    {
        self::assertSame($expected, Paths::filenameFromStreamUri($uri));
    }

    public function testTerminalStatuses(): void
    {
        self::assertTrue(PredictionStatus::Completed->isTerminal());
        self::assertTrue(PredictionStatus::Failed->isTerminal());
        self::assertTrue(PredictionStatus::Cancelled->isTerminal());
        self::assertTrue(PredictionStatus::Timeout->isTerminal());

        self::assertFalse(PredictionStatus::Created->isTerminal());
        self::assertFalse(PredictionStatus::Queued->isTerminal());
        self::assertFalse(PredictionStatus::Processing->isTerminal());
    }

    public function testOnlyCompletedIsATerminalSuccess(): void
    {
        self::assertFalse(PredictionStatus::Completed->isFailure());
        self::assertTrue(PredictionStatus::Failed->isFailure());
        self::assertTrue(PredictionStatus::Cancelled->isFailure());
        self::assertTrue(PredictionStatus::Timeout->isFailure());
        self::assertFalse(PredictionStatus::Processing->isFailure());
    }

    public function testAnUnknownStatusStringDoesNotMapToACase(): void
    {
        self::assertNull(PredictionStatus::tryFrom('some-future-state'));
    }
}
