<?php

declare(strict_types=1);

namespace WaveSpeed\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use WaveSpeed\Client;
use WaveSpeed\Exception\ApiException;
use WaveSpeed\Exception\ConfigurationException;
use WaveSpeed\Exception\FileNotFoundException;
use WaveSpeed\Tests\Support\TestCase;

#[CoversClass(Client::class)]
final class UploadTest extends TestCase
{
    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            foreach ((array) glob($this->tempDir . DIRECTORY_SEPARATOR . '*') as $path) {
                if (is_string($path) && is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($this->tempDir);
        }
        $this->tempDir = null;

        parent::tearDown();
    }

    public function testUploadingAPathSendsATicketRequestThenThePayload(): void
    {
        $path = $this->tempFile('sunset.png', 'binary-image-bytes');
        $sentBytes = null;

        $this->http
            ->pushJson([
                'code' => 200,
                'data' => [
                    'upload' => [
                        'method' => 'PUT',
                        'url' => 'https://storage.example.com/put-here',
                        'headers' => ['Content-Type' => 'image/png'],
                    ],
                    'download_url' => 'https://cdn.wavespeed.ai/media/sunset.png',
                ],
            ])
            // Read the payload while the request is in flight: the SDK closes
            // the handle it opened as soon as upload() returns.
            ->pushHandler(function ($request) use (&$sentBytes) {
                self::assertIsResource($request->body);
                $sentBytes = stream_get_contents($request->body);

                return new \WaveSpeed\Http\Response(200, '');
            });

        $url = $this->client()->upload($path);

        self::assertSame('https://cdn.wavespeed.ai/media/sunset.png', $url);
        self::assertSame(2, $this->http->requestCount());

        $ticket = $this->http->requestAt(0);
        self::assertSame('POST', $ticket->method);
        self::assertSame('https://api.wavespeed.ai/api/v3/media/uploads', $ticket->url);
        self::assertSame([
            'filename' => 'sunset.png',
            'size' => 18,
            'content_type' => 'image/png',
        ], $this->http->bodyAt(0));

        $put = $this->http->requestAt(1);
        self::assertSame('PUT', $put->method);
        self::assertSame('https://storage.example.com/put-here', $put->url);
        self::assertSame(['Content-Type' => 'image/png'], $put->headers);
        self::assertSame('binary-image-bytes', $sentBytes);
    }

    public function testUploadStreamsTheFileRatherThanBufferingIt(): void
    {
        $path = $this->tempFile('clip.mp4', str_repeat('x', 1024));
        $this->queueTicket(downloadUrl: 'https://cdn.wavespeed.ai/media/clip.mp4');

        $this->client()->upload($path);

        // A stream body is what lets a multi-gigabyte upload stay cheap.
        self::assertIsResource($this->http->requestAt(1)->body);
        self::assertSame(1024, $this->http->bodyAt(0)['size']);
        self::assertSame('video/mp4', $this->http->bodyAt(0)['content_type']);
    }

    public function testUploadingAStreamUsesAGenericFilename(): void
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'in-memory-bytes');
        rewind($stream);

        $this->queueTicket(downloadUrl: 'https://cdn.wavespeed.ai/media/upload');

        $url = $this->client()->upload($stream);

        self::assertSame('https://cdn.wavespeed.ai/media/upload', $url);
        // php://temp is a stream URI, not a filename, and carries no extension
        // to guess a content type from.
        self::assertSame(['filename' => 'upload', 'size' => 15], $this->http->bodyAt(0));

        // A caller-owned stream must survive the call: still readable, and its
        // contents untouched.
        rewind($stream);
        self::assertSame('in-memory-bytes', stream_get_contents($stream));
        fclose($stream);
    }

    public function testUploadingAStreamRespectsItsCurrentPosition(): void
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'skip-me:payload');
        fseek($stream, 8);

        $this->queueTicket();

        $this->client()->upload($stream);

        self::assertSame(7, $this->http->bodyAt(0)['size']);
        fclose($stream);
    }

    public function testUploadingAFileHandleKeepsItsName(): void
    {
        $path = $this->tempFile('portrait.jpg', 'jpeg-bytes');
        $stream = fopen($path, 'rb');
        self::assertIsResource($stream);

        $this->queueTicket();

        $this->client()->upload($stream);

        self::assertSame('portrait.jpg', $this->http->bodyAt(0)['filename']);
        self::assertSame('image/jpeg', $this->http->bodyAt(0)['content_type']);
        fclose($stream);
    }

    public function testUploadingAnUnknownExtensionOmitsTheContentType(): void
    {
        $path = $this->tempFile('weights.unknownext', 'data');
        $this->queueTicket();

        $this->client()->upload($path);

        self::assertArrayNotHasKey('content_type', $this->http->bodyAt(0));
    }

    public function testUploadWithoutAnApiKeyThrowsBeforeTouchingTheNetwork(): void
    {
        $path = $this->tempFile('sunset.png', 'bytes');

        try {
            $this->client(apiKey: null)->upload($path);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('API key is required', $e->getMessage());
        }

        self::assertSame(0, $this->http->requestCount());
    }

    public function testUploadingAMissingPathThrows(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage('File not found:');

        $this->client()->upload(__DIR__ . '/does-not-exist.png');
    }

    public function testUploadingSomethingThatIsNeitherPathNorStreamThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('expects a file path or an open stream resource, got int');

        /** @phpstan-ignore-next-line intentionally wrong type */
        $this->client()->upload(42);
    }

    public function testATicketHttpErrorThrows(): void
    {
        $path = $this->tempFile('sunset.png', 'bytes');
        $this->http->pushBody('quota exceeded', 429);

        try {
            $this->client()->upload($path);
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertStringContainsString('Failed to create upload: HTTP 429', $e->getMessage());
            self::assertSame(429, $e->statusCode);
        }
    }

    public function testATicketBusinessErrorThrows(): void
    {
        $path = $this->tempFile('sunset.png', 'bytes');
        $this->http->pushJson(['code' => 4001, 'message' => 'file too large']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Upload failed: file too large');

        $this->client()->upload($path);
    }

    public function testATicketBusinessErrorWithoutAMessageThrows(): void
    {
        $path = $this->tempFile('sunset.png', 'bytes');
        $this->http->pushJson(['code' => 500]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Upload failed: Unknown error');

        $this->client()->upload($path);
    }

    public function testAnUnusableUploadInstructionThrows(): void
    {
        $path = $this->tempFile('sunset.png', 'bytes');
        $this->http->pushJson([
            'code' => 200,
            'data' => ['upload' => ['method' => 'POST', 'url' => 'https://storage.example.com/put-here']],
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Upload failed: invalid upload instruction');

        $this->client()->upload($path);
    }

    public function testAFailedPutThrows(): void
    {
        $path = $this->tempFile('sunset.png', 'bytes');
        // Ticket succeeds, then storage rejects the payload.
        $this->http
            ->pushJson([
                'code' => 200,
                'data' => [
                    'upload' => ['method' => 'PUT', 'url' => 'https://storage.example.com/put-here'],
                    'download_url' => 'https://cdn.wavespeed.ai/media/sunset.png',
                ],
            ])
            ->pushBody('signature expired', 403);

        try {
            $this->client()->upload($path);
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertStringContainsString('Failed to upload file: HTTP 403', $e->getMessage());
            self::assertStringContainsString('signature expired', $e->getMessage());
        }
    }

    public function testAMissingDownloadUrlThrows(): void
    {
        $path = $this->tempFile('sunset.png', 'bytes');
        $this->http
            ->pushJson([
                'code' => 200,
                'data' => ['upload' => ['method' => 'PUT', 'url' => 'https://storage.example.com/put-here']],
            ])
            ->pushBody('', 200);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Upload failed: no download_url in response');

        $this->client()->upload($path);
    }

    private function queueTicket(string $downloadUrl = 'https://cdn.wavespeed.ai/media/sunset.png'): void
    {
        $this->http
            ->pushJson([
                'code' => 200,
                'data' => [
                    'upload' => [
                        'method' => 'PUT',
                        'url' => 'https://storage.example.com/put-here',
                        'headers' => ['Content-Type' => 'image/png'],
                    ],
                    'download_url' => $downloadUrl,
                ],
            ])
            ->pushBody('', 200);
    }

    /**
     * Write a file under a per-test directory, so the name on disk is exactly
     * the name the SDK should report to the API.
     */
    private function tempFile(string $name, string $contents): string
    {
        if ($this->tempDir === null) {
            $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('wavespeed-test-', true);
            mkdir($dir);
            $this->tempDir = $dir;
        }

        $path = $this->tempDir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $contents);

        return $path;
    }
}
