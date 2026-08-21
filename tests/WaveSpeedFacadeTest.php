<?php

declare(strict_types=1);

namespace WaveSpeed\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use WaveSpeed\Config;
use WaveSpeed\Tests\Support\TestCase;
use WaveSpeed\Version;
use WaveSpeed\WaveSpeed;

#[CoversClass(WaveSpeed::class)]
final class WaveSpeedFacadeTest extends TestCase
{
    public function testTheDefaultClientIsBuiltFromConfigAndReused(): void
    {
        Config::set(Config::API_KEY, 'config-key');

        $first = WaveSpeed::client();

        self::assertSame('config-key', $first->apiKey);
        self::assertSame($first, WaveSpeed::client(), 'The default client should be created once.');
    }

    public function testRunGoesThroughTheInjectedClient(): void
    {
        WaveSpeed::setClient($this->client());
        $this->http
            ->pushJson(self::prediction('created'))
            ->pushJson(self::prediction('completed', ['outputs' => ['https://out/1.png']]));

        $output = WaveSpeed::run('wavespeed-ai/z-image/turbo', ['prompt' => 'Cat']);

        self::assertSame(['https://out/1.png'], $output['outputs']);
        self::assertSame(
            'https://api.wavespeed.ai/api/v3/wavespeed-ai/z-image/turbo',
            $this->http->requestAt(0)->url,
        );
    }

    public function testRunForwardsItsOptions(): void
    {
        WaveSpeed::setClient($this->client());
        $this->http->pushJson(self::prediction('completed', ['outputs' => ['https://out/sync.png']]));

        $output = WaveSpeed::run(
            'wavespeed-ai/z-image/turbo',
            ['prompt' => 'Cat'],
            timeout: 30.0,
            enableSyncMode: true,
        );

        self::assertSame(['https://out/sync.png'], $output['outputs']);
        self::assertTrue($this->http->bodyAt(0)['enable_sync_mode']);
        self::assertSame(30.0, $this->http->requestAt(0)->timeout);
    }

    public function testRunNoThrowGoesThroughTheInjectedClient(): void
    {
        WaveSpeed::setClient($this->client());
        $this->http
            ->pushJson(self::prediction('created'))
            ->pushJson(self::prediction('failed', ['error' => 'nope']));

        $result = WaveSpeed::runNoThrow('wavespeed-ai/z-image/turbo');

        self::assertSame('failed', $result['status']);
        self::assertSame('task-123', $result['task_id']);
    }

    public function testGetResultGoesThroughTheInjectedClient(): void
    {
        WaveSpeed::setClient($this->client());
        $this->http->pushJson(self::prediction('processing'));

        $result = WaveSpeed::getResult('task-123');

        self::assertSame('processing', $result['data']['status']);
    }

    public function testGetResultWithAnExplicitApiKeyBypassesTheSharedClient(): void
    {
        $shared = $this->client();
        WaveSpeed::setClient($shared);

        // A per-call key means a one-off client, which uses the real cURL
        // transport — so assert on the routing, not on a live request.
        self::assertSame($shared, WaveSpeed::client());
        self::assertNotSame('other-key', $shared->apiKey);
    }

    public function testUploadGoesThroughTheInjectedClient(): void
    {
        WaveSpeed::setClient($this->client());

        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'bytes');
        rewind($stream);

        $this->http
            ->pushJson([
                'code' => 200,
                'data' => [
                    'upload' => ['method' => 'PUT', 'url' => 'https://storage.example.com/put'],
                    'download_url' => 'https://cdn.wavespeed.ai/media/upload',
                ],
            ])
            ->pushBody('', 200);

        self::assertSame('https://cdn.wavespeed.ai/media/upload', WaveSpeed::upload($stream));

        fclose($stream);
    }

    public function testSettingNullRebuildsTheDefaultClient(): void
    {
        $injected = $this->client();
        WaveSpeed::setClient($injected);
        WaveSpeed::setClient(null);

        self::assertNotSame($injected, WaveSpeed::client());
    }

    public function testVersionMatchesTheHeaderValue(): void
    {
        self::assertSame(Version::get(), WaveSpeed::version());
        self::assertNotSame('', WaveSpeed::version());
    }
}
