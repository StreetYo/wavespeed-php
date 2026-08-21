<?php

declare(strict_types=1);

namespace WaveSpeed\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use WaveSpeed\Exception\ApiException;
use WaveSpeed\Exception\ConnectionException;
use WaveSpeed\Http\CurlHttpClient;
use WaveSpeed\Http\Request;
use WaveSpeed\Http\Response;

#[CoversClass(Request::class)]
#[CoversClass(Response::class)]
#[CoversClass(CurlHttpClient::class)]
final class HttpTest extends TestCase
{
    public function testHeaderLinesAreRenderedForTheTransport(): void
    {
        $request = new Request(
            method: 'POST',
            url: 'https://api.wavespeed.ai/api/v3/model',
            headers: ['Authorization' => 'Bearer k', 'X-Client-Name' => 'wavespeed-php'],
        );

        self::assertSame(
            ['Authorization: Bearer k', 'X-Client-Name: wavespeed-php'],
            $request->headerLines(),
        );
    }

    public function testResponseDecodesJson(): void
    {
        $response = new Response(200, '{"data":{"id":"task-1"}}');

        self::assertSame(['data' => ['id' => 'task-1']], $response->json());
        self::assertTrue($response->isSuccessful());
    }

    public function testResponseRejectsMalformedJson(): void
    {
        $response = new Response(502, '<html>bad gateway</html>');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Failed to decode API response as JSON');

        $response->json();
    }

    public function testResponseRejectsAJsonScalar(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('expected a JSON object, got string');

        (new Response(200, '"just a string"'))->json();
    }

    public function testResponseHeadersAreReadCaseInsensitively(): void
    {
        $response = new Response(200, '{}', ['content-type' => 'application/json']);

        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertNull($response->header('X-Missing'));
    }

    public function testNon2xxStatusesAreNotSuccessful(): void
    {
        self::assertFalse((new Response(404, ''))->isSuccessful());
        self::assertFalse((new Response(500, ''))->isSuccessful());
        self::assertTrue((new Response(204, ''))->isSuccessful());
    }

    /**
     * Exercises the real transport against a closed local port: no external
     * network involved, but the cURL error path is genuinely executed.
     */
    #[Group('curl')]
    public function testCurlTransportTurnsATransportFailureIntoAConnectionException(): void
    {
        $client = new CurlHttpClient();

        try {
            $client->send(new Request(
                method: 'GET',
                // Port 1 on loopback: nothing listens there.
                url: 'http://127.0.0.1:1/result',
                connectTimeout: 1.0,
                timeout: 2.0,
            ));
            self::fail('Expected a ConnectionException.');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('GET http://127.0.0.1:1/result failed', $e->getMessage());
            self::assertStringContainsString('cURL error', $e->getMessage());
        }
    }
}
