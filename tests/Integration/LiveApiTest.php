<?php

declare(strict_types=1);

namespace WaveSpeed\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use WaveSpeed\Client;
use WaveSpeed\Support\StderrLogger;

/**
 * Talks to the real API. Excluded from the default suite; run it with:
 *
 *     WAVESPEED_API_KEY=... vendor/bin/phpunit --group integration
 *
 * These cost credits, so keep them few and cheap.
 */
#[Group('integration')]
final class LiveApiTest extends TestCase
{
    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $key = getenv('WAVESPEED_API_KEY');
        if (!is_string($key) || $key === '') {
            self::markTestSkipped('Set WAVESPEED_API_KEY to run the integration tests.');
        }

        $this->apiKey = $key;
    }

    public function testRunAgainstTheLiveApi(): void
    {
        $client = new Client(apiKey: $this->apiKey, logger: new StderrLogger());

        $output = $client->run(
            'wavespeed-ai/z-image/turbo',
            ['prompt' => 'A cat sitting on a windowsill'],
            timeout: 300.0,
        );

        self::assertNotEmpty($output['outputs']);
        self::assertIsString($output['outputs'][0]);
        self::assertStringStartsWith('http', $output['outputs'][0]);
    }

    public function testUploadAgainstTheLiveApi(): void
    {
        $client = new Client(apiKey: $this->apiKey, logger: new StderrLogger());

        $path = tempnam(sys_get_temp_dir(), 'ws-upload-') ?: null;
        self::assertNotNull($path);
        $pngPath = $path . '.png';
        rename($path, $pngPath);

        // Smallest valid PNG: a single transparent pixel.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
            true,
        );
        self::assertIsString($png);
        file_put_contents($pngPath, $png);

        try {
            $url = $client->upload($pngPath);

            self::assertStringStartsWith('http', $url);
        } finally {
            if (is_file($pngPath)) {
                unlink($pngPath);
            }
        }
    }

    public function testGetResultRecoversATask(): void
    {
        $client = new Client(apiKey: $this->apiKey, logger: new StderrLogger());

        $submitted = $client->runNoThrow(
            'wavespeed-ai/z-image/turbo',
            ['prompt' => 'A quiet harbour at dawn'],
            timeout: 300.0,
        );

        self::assertNotSame('unknown', $submitted['task_id']);

        $result = $client->getResult($submitted['task_id']);

        self::assertSame($submitted['task_id'], $result['data']['id']);
        self::assertArrayHasKey('status', $result['data']);
    }
}
