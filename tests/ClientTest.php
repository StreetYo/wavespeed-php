<?php

declare(strict_types=1);

namespace WaveSpeed\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use WaveSpeed\Client;
use WaveSpeed\Config;
use WaveSpeed\Exception\ApiException;
use WaveSpeed\Exception\ConfigurationException;
use WaveSpeed\Exception\ConnectionException;
use WaveSpeed\Exception\PredictionFailedException;
use WaveSpeed\Exception\SubmissionException;
use WaveSpeed\Exception\SyncModeTimeoutException;
use WaveSpeed\Exception\TimeoutException;
use WaveSpeed\PredictionStatus;
use WaveSpeed\Tests\Support\FakeClock;
use WaveSpeed\Tests\Support\TestCase;
use WaveSpeed\Version;

#[CoversClass(Client::class)]
final class ClientTest extends TestCase
{
    // ---------------------------------------------------------------- construction

    public function testInitWithApiKey(): void
    {
        $client = new Client(apiKey: 'test-key');

        self::assertSame('test-key', $client->apiKey);
        self::assertSame('https://api.wavespeed.ai', $client->baseUrl);
    }

    public function testInitWithCustomBaseUrlDropsTrailingSlash(): void
    {
        $client = new Client(apiKey: 'test-key', baseUrl: 'https://custom.example.com/');

        self::assertSame('https://custom.example.com', $client->baseUrl);
    }

    public function testInitReadsDefaultsFromConfig(): void
    {
        Config::apply([
            Config::API_KEY => 'config-key',
            Config::BASE_URL => 'https://config.example.com',
            Config::MAX_RETRIES => 3,
            Config::MAX_CONNECTION_RETRIES => 7,
            Config::RETRY_INTERVAL => 2.5,
            Config::CONNECTION_TIMEOUT => 4.0,
        ]);

        $client = new Client();

        self::assertSame('config-key', $client->apiKey);
        self::assertSame('https://config.example.com', $client->baseUrl);
        self::assertSame(3, $client->maxRetries);
        self::assertSame(7, $client->maxConnectionRetries);
        self::assertSame(2.5, $client->retryInterval);
        self::assertSame(4.0, $client->connectionTimeout);
    }

    public function testInitReadsApiKeyFromEnvironment(): void
    {
        putenv('WAVESPEED_API_KEY=env-key');
        Config::reset();

        self::assertSame('env-key', (new Client())->apiKey);
    }

    public function testConstructorArgumentsWinOverConfig(): void
    {
        Config::set(Config::MAX_RETRIES, 9);

        self::assertSame(1, (new Client(apiKey: 'k', maxRetries: 1))->maxRetries);
    }

    // ---------------------------------------------------------------- headers

    public function testRequestWithoutApiKeyThrows(): void
    {
        $client = $this->client(apiKey: null);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('API key is required');

        $client->run('wavespeed-ai/z-image/turbo', ['prompt' => 'Cat']);
    }

    public function testRequestSendsAuthAndAttributionHeaders(): void
    {
        $this->http
            ->pushJson(self::prediction('created'))
            ->pushJson(self::prediction('completed', ['outputs' => ['https://out/1.png']]));

        $this->client()->run('wavespeed-ai/z-image/turbo', ['prompt' => 'Cat']);

        $headers = $this->http->requestAt(0)->headers;

        self::assertSame('Bearer test-key', $headers['Authorization']);
        self::assertSame('application/json', $headers['Content-Type']);
        self::assertSame('wavespeed-php', $headers['X-Client-Name']);
        self::assertSame(Version::get(), $headers['X-Client-Version']);
        self::assertContains(
            $headers['X-Client-OS'],
            ['windows', 'darwin', 'linux', 'bsd', 'solaris', 'unknown'],
        );

        // Attribution travels on the polling GET too.
        self::assertSame('wavespeed-php', $this->http->requestAt(1)->headers['X-Client-Name']);
    }

    public function testClientNameCanBeOverriddenPerClient(): void
    {
        self::assertSame('my-app', $this->client(clientName: 'my-app')->clientName);
    }

    public function testClientNameEnvironmentVariableWinsOverArgument(): void
    {
        putenv('WAVESPEED_CLIENT_NAME=from-env');

        self::assertSame('from-env', $this->client(clientName: 'my-app')->clientName);
    }

    // ---------------------------------------------------------------- submission

    public function testSubmitPostsToTheModelEndpoint(): void
    {
        $this->http
            ->pushJson(self::prediction('created'))
            ->pushJson(self::prediction('completed', ['outputs' => ['https://out/1.png']]));

        $this->client()->run('wavespeed-ai/z-image/turbo', ['prompt' => 'Cat', 'seed' => 42]);

        $submit = $this->http->requestAt(0);

        self::assertSame('POST', $submit->method);
        self::assertSame('https://api.wavespeed.ai/api/v3/wavespeed-ai/z-image/turbo', $submit->url);
        self::assertSame(['prompt' => 'Cat', 'seed' => 42], $this->http->bodyAt(0));

        $poll = $this->http->requestAt(1);
        self::assertSame('GET', $poll->method);
        self::assertSame('https://api.wavespeed.ai/api/v3/predictions/task-123/result', $poll->url);
    }

    public function testSubmitWithoutInputSendsAnEmptyJsonObject(): void
    {
        $this->http
            ->pushJson(self::prediction('created'))
            ->pushJson(self::prediction('completed'));

        $this->client()->run('wavespeed-ai/z-image/turbo');

        self::assertSame('{}', $this->http->requestAt(0)->body);
    }

    public function testSubmitFailureThrowsSubmissionException(): void
    {
        $this->http->pushBody('{"message":"bad request"}', 400);

        try {
            $this->client()->run('wavespeed-ai/z-image/turbo', ['prompt' => 'Cat']);
            self::fail('Expected a SubmissionException.');
        } catch (SubmissionException $e) {
            self::assertStringContainsString('Failed to submit prediction: HTTP 400', $e->getMessage());
            self::assertSame(400, $e->statusCode);
            self::assertSame('{"message":"bad request"}', $e->responseBody);
        }
    }

    public function testSubmitWithoutRequestIdThrows(): void
    {
        $this->http->pushJson(['data' => ['status' => 'created']]);

        $this->expectException(SubmissionException::class);
        $this->expectExceptionMessage('No request ID in response');

        $this->client()->run('wavespeed-ai/z-image/turbo');
    }

    public function testSubmitConnectionErrorIsNotRetried(): void
    {
        $this->http->pushConnectionError();

        try {
            $this->client(maxRetries: 3)->run('wavespeed-ai/z-image/turbo');
            self::fail('Expected a SubmissionException.');
        } catch (SubmissionException $e) {
            self::assertStringContainsString('did not return a response', $e->getMessage());
            self::assertInstanceOf(ConnectionException::class, $e->getPrevious());
        }

        // The POST is not idempotent: exactly one attempt, no retry.
        self::assertSame(1, $this->http->requestCount());
        self::assertSame(0, $this->clock->sleepCount());
    }

    public function testTaskRetriesDoNotRepeatAFailedSubmission(): void
    {
        $this->http->pushBody('server exploded', 500);

        $this->expectException(SubmissionException::class);

        try {
            $this->client(maxRetries: 5)->run('wavespeed-ai/z-image/turbo');
        } finally {
            self::assertSame(1, $this->http->requestCount());
        }
    }

    // ---------------------------------------------------------------- run

    public function testRunReturnsOutputs(): void
    {
        $this->http
            ->pushJson(self::prediction('created'))
            ->pushJson(self::prediction('processing'))
            ->pushJson(self::prediction('completed', ['outputs' => ['https://out/1.png', 'https://out/2.png']]));

        $output = $this->client()->run('wavespeed-ai/z-image/turbo', ['prompt' => 'Cat'], pollInterval: 0.5);

        self::assertSame(['outputs' => ['https://out/1.png', 'https://out/2.png']], $output);
        self::assertSame([0.5], $this->clock->sleeps);
    }

    public function testRunOnMissingOutputsReturnsAnEmptyList(): void
    {
        $this->http
            ->pushJson(self::prediction('created'))
            ->pushJson(self::prediction('completed'));

        self::assertSame(['outputs' => []], $this->client()->run('wavespeed-ai/z-image/turbo'));
    }

    public function testRunOnFailedPredictionThrows(): void
    {
        $this->http
            ->pushJson(self::prediction('created'))
            ->pushJson(self::prediction('failed', ['error' => 'NSFW content detected']));

        try {
            $this->client()->run('wavespeed-ai/z-image/turbo');
            self::fail('Expected a PredictionFailedException.');
        } catch (PredictionFailedException $e) {
            self::assertSame('Prediction failed (task_id: task-123): NSFW content detected', $e->getMessage());
            self::assertSame('failed', $e->predictionStatus);
            self::assertSame('task-123', $e->taskId);
        }
    }

    public function testRunOnFailureWithoutAnErrorMessage(): void
    {
        $this->http
            ->pushJson(self::prediction('created'))
            ->pushJson(self::prediction('failed'));

        $this->expectExceptionMessage('Prediction failed (task_id: task-123): Unknown error');

        $this->client()->run('wavespeed-ai/z-image/turbo');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function terminalFailureStatuses(): iterable
    {
        yield 'failed' => ['failed'];
        yield 'cancelled' => ['cancelled'];
        yield 'timeout' => ['timeout'];
    }

    #[DataProvider('terminalFailureStatuses')]
    public function testEveryTerminalStatusStopsPolling(string $status): void
    {
        $this->http
            ->pushJson(self::prediction('created'))
            ->pushJson(self::prediction($status, ['error' => 'nope']));

        try {
            $this->client()->run('wavespeed-ai/z-image/turbo');
            self::fail('Expected a PredictionFailedException.');
        } catch (PredictionFailedException $e) {
            self::assertSame($status, $e->predictionStatus);
        }

        self::assertSame(2, $this->http->requestCount());
        self::assertSame(0, $this->clock->sleepCount());
    }

    public function testAnUnknownStatusKeepsPolling(): void
    {
        $this->http
            ->pushJson(self::prediction('created'))
            ->pushJson(self::prediction('some-future-state'))
            ->pushJson(self::prediction('completed', ['outputs' => ['https://out/1.png']]));

        $output = $this->client()->run('wavespeed-ai/z-image/turbo');

        self::assertSame(['https://out/1.png'], $output['outputs']);
    }

    public function testRunTimesOutWhileWaiting(): void
    {
        $this->http
            ->pushJson(self::prediction('created'))
            ->pushJson(self::prediction('processing'))
            ->pushJson(self::prediction('processing'));

        try {
            // The fake clock advances on sleep: 0s, 3s, then 6s > 5s budget.
            $this->client()->run('wavespeed-ai/z-image/turbo', timeout: 5.0, pollInterval: 3.0);
            self::fail('Expected a TimeoutException.');
        } catch (TimeoutException $e) {
            self::assertSame('Prediction timed out after 5 seconds (task_id: task-123)', $e->getMessage());
            self::assertSame('task-123', $e->taskId);
        }
    }

    public function testLocalTimeoutIsNotAppliedWhenNoBudgetIsGiven(): void
    {
        $this->clock = new FakeClock(tickPerCall: 100_000.0);
        $this->http
            ->pushJson(self::prediction('created'))
            ->pushJson(self::prediction('processing'))
            ->pushJson(self::prediction('completed', ['outputs' => ['https://out/1.png']]));

        $output = $this->client()->run('wavespeed-ai/z-image/turbo', timeout: null);

        self::assertSame(['https://out/1.png'], $output['outputs']);
    }

    public function testARetryableFailureSubmitsAReplacementTask(): void
    {
        $this->http
            ->pushJson(['data' => ['id' => 'task-1', 'status' => 'created']])
            ->pushBody('upstream unavailable', 503)
            ->pushJson(['data' => ['id' => 'task-2', 'status' => 'created']])
            ->pushJson(['data' => ['id' => 'task-2', 'status' => 'completed', 'outputs' => ['https://out/2.png']]]);

        $client = $this->client(maxRetries: 1, maxConnectionRetries: 0, retryInterval: 2.0);
        $output = $client->run('wavespeed-ai/z-image/turbo');

        self::assertSame(['https://out/2.png'], $output['outputs']);
        self::assertSame(4, $this->http->requestCount());
        self::assertSame([2.0], $this->clock->sleeps);
        self::assertTrue($this->logger->contains('Task attempt 1/2 failed'));
    }

    public function testTaskRetriesAreExhaustedAndTheLastErrorSurfaces(): void
    {
        $this->http
            ->pushJson(self::prediction('created'))
            ->pushBody('boom', 500)
            ->pushJson(self::prediction('created'))
            ->pushBody('boom again', 500);

        try {
            $this->client(maxRetries: 1, maxConnectionRetries: 0)->run('wavespeed-ai/z-image/turbo');
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertStringContainsString('boom again', $e->getMessage());
            self::assertSame(500, $e->statusCode);
        }

        self::assertSame(4, $this->http->requestCount());
    }

    public function testAPredictionFailureIsNotRetriedAtTheTaskLevel(): void
    {
        $this->http
            ->pushJson(self::prediction('created'))
            ->pushJson(self::prediction('failed', ['error' => 'model said no']));

        $this->expectException(PredictionFailedException::class);

        try {
            $this->client(maxRetries: 3)->run('wavespeed-ai/z-image/turbo');
        } finally {
            // No replacement task: the platform gave a verdict, not a hiccup.
            self::assertSame(2, $this->http->requestCount());
        }
    }

    // ---------------------------------------------------------------- sync mode

    public function testSyncModeReturnsOutputsFromTheSubmitResponse(): void
    {
        $this->http->pushJson(self::prediction('completed', ['outputs' => ['https://out/sync.png']]));

        $output = $this->client()->run(
            'wavespeed-ai/z-image/turbo',
            ['prompt' => 'Cat'],
            enableSyncMode: true,
        );

        self::assertSame(['https://out/sync.png'], $output['outputs']);
        self::assertSame(1, $this->http->requestCount());
        self::assertSame(
            ['prompt' => 'Cat', 'enable_sync_mode' => true],
            $this->http->bodyAt(0),
        );
    }

    public function testSyncModeTimeoutThrowsWithTheTaskIdAndResultUrl(): void
    {
        $this->http->pushJson(['data' => [
            'id' => 'task-sync',
            'status' => 'processing',
            'code' => 5004,
            'error' => 'Sync mode timed out',
            'urls' => ['get' => 'https://api.wavespeed.ai/api/v3/predictions/task-sync/result'],
        ]]);

        try {
            $this->client()->run('wavespeed-ai/z-image/turbo', enableSyncMode: true);
            self::fail('Expected a SyncModeTimeoutException.');
        } catch (SyncModeTimeoutException $e) {
            self::assertStringContainsString('Sync mode timed out (task_id: task-sync)', $e->getMessage());
            self::assertStringContainsString('Query the result later at: https://api.wavespeed.ai', $e->getMessage());
            self::assertSame('task-sync', $e->taskId);
            self::assertSame(
                'https://api.wavespeed.ai/api/v3/predictions/task-sync/result',
                $e->resultUrl,
            );
        }

        // No silent fallback to polling: one request, and the caller decides.
        self::assertSame(1, $this->http->requestCount());
    }

    public function testSyncModeFailureIsAPredictionFailure(): void
    {
        $this->http->pushJson(['data' => [
            'id' => 'task-sync',
            'status' => 'failed',
            'error' => 'invalid prompt',
        ]]);

        try {
            $this->client()->run('wavespeed-ai/z-image/turbo', enableSyncMode: true);
            self::fail('Expected a PredictionFailedException.');
        } catch (PredictionFailedException $e) {
            self::assertSame('Prediction failed (task_id: task-sync): invalid prompt', $e->getMessage());
            self::assertSame('task-sync', $e->taskId);
        }
    }

    // ---------------------------------------------------------------- runNoThrow

    public function testRunNoThrowOnSuccess(): void
    {
        $this->http
            ->pushJson(self::prediction('created'))
            ->pushJson(self::prediction('completed', ['outputs' => ['https://out/1.png']]));

        $result = $this->client()->runNoThrow('wavespeed-ai/z-image/turbo', ['prompt' => 'Cat']);

        self::assertSame([
            'status' => 'completed',
            'outputs' => ['https://out/1.png'],
            'task_id' => 'task-123',
            'error' => null,
        ], $result);
    }

    public function testRunNoThrowOnFailure(): void
    {
        $this->http
            ->pushJson(self::prediction('created'))
            ->pushJson(self::prediction('failed', ['error' => 'model said no']));

        $result = $this->client()->runNoThrow('wavespeed-ai/z-image/turbo');

        self::assertSame('failed', $result['status']);
        self::assertNull($result['outputs']);
        self::assertSame('task-123', $result['task_id']);
        self::assertStringContainsString('model said no', (string) $result['error']);
    }

    public function testRunNoThrowOnSubmissionErrorReportsUnknownTask(): void
    {
        $this->http->pushConnectionError();

        $result = $this->client()->runNoThrow('wavespeed-ai/z-image/turbo');

        self::assertSame('failed', $result['status']);
        self::assertSame('unknown', $result['task_id']);
        self::assertStringContainsString('did not return a response', (string) $result['error']);
        self::assertSame(1, $this->http->requestCount());
    }

    public function testRunNoThrowOnSyncTimeoutReportsProcessing(): void
    {
        $this->http->pushJson(['data' => [
            'id' => 'task-sync',
            'status' => 'processing',
            'code' => 5004,
            'error' => 'Sync mode timed out',
        ]]);

        $result = $this->client()->runNoThrow('wavespeed-ai/z-image/turbo', enableSyncMode: true);

        self::assertSame(PredictionStatus::Processing->value, $result['status']);
        self::assertNull($result['outputs']);
        self::assertSame('task-sync', $result['task_id']);
        self::assertStringContainsString('Sync mode timed out', (string) $result['error']);
    }

    public function testRunNoThrowOnSyncSuccess(): void
    {
        $this->http->pushJson(self::prediction('completed', [
            'id' => 'task-sync',
            'outputs' => ['https://out/sync.png'],
        ]));

        $result = $this->client()->runNoThrow('wavespeed-ai/z-image/turbo', enableSyncMode: true);

        self::assertSame('completed', $result['status']);
        self::assertSame(['https://out/sync.png'], $result['outputs']);
        self::assertSame('task-sync', $result['task_id']);
    }

    public function testRunNoThrowOnLocalTimeoutKeepsTheTaskId(): void
    {
        $this->http
            ->pushJson(self::prediction('created'))
            ->pushJson(self::prediction('processing'))
            ->pushJson(self::prediction('processing'));

        $result = $this->client()->runNoThrow(
            'wavespeed-ai/z-image/turbo',
            timeout: 5.0,
            pollInterval: 3.0,
        );

        self::assertSame('failed', $result['status']);
        self::assertSame('task-123', $result['task_id']);
        self::assertStringContainsString('timed out', (string) $result['error']);
    }

    public function testRunNoThrowRetriesBeforeGivingUp(): void
    {
        $this->http
            ->pushJson(self::prediction('created'))
            ->pushBody('boom', 502)
            ->pushJson(self::prediction('created'))
            ->pushJson(self::prediction('completed', ['outputs' => ['https://out/1.png']]));

        $client = $this->client(maxRetries: 1, maxConnectionRetries: 0);
        $result = $client->runNoThrow('wavespeed-ai/z-image/turbo');

        self::assertSame('completed', $result['status']);
        self::assertSame(4, $this->http->requestCount());
    }

    // ---------------------------------------------------------------- getResult

    public function testGetResultReturnsTheFullResponse(): void
    {
        $payload = self::prediction('completed', ['outputs' => ['https://out/1.png']]);
        $this->http->pushJson($payload);

        self::assertSame($payload, $this->client()->getResult('task-123'));
        self::assertSame(
            'https://api.wavespeed.ai/api/v3/predictions/task-123/result',
            $this->http->requestAt(0)->url,
        );
    }

    public function testGetResultRetriesTransientServerErrors(): void
    {
        $this->http
            ->pushBody('gateway timeout', 504)
            ->pushBody('bad gateway', 502)
            ->pushJson(self::prediction('completed'));

        $client = $this->client(maxConnectionRetries: 3, retryInterval: 1.0);
        $result = $client->getResult('task-123');

        self::assertSame('completed', $result['data']['status']);
        self::assertSame(3, $this->http->requestCount());
        // Backoff grows with the attempt number: 1s then 2s.
        self::assertSame([1.0, 2.0], $this->clock->sleeps);
        self::assertTrue($this->logger->contains('Server error (HTTP 504)'));
    }

    public function testGetResultDoesNotRetryClientErrors(): void
    {
        $this->http->pushBody('not found', 404);

        try {
            $this->client(maxConnectionRetries: 5)->getResult('task-404');
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertStringContainsString('Failed to get result for task task-404: HTTP 404', $e->getMessage());
            self::assertSame(404, $e->statusCode);
            self::assertSame('task-404', $e->taskId);
        }

        self::assertSame(1, $this->http->requestCount());
    }

    public function testGetResultGivesUpAfterExhaustingRetries(): void
    {
        $this->http
            ->pushBody('boom', 500)
            ->pushBody('boom', 500);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('HTTP 500');

        try {
            $this->client(maxConnectionRetries: 1)->getResult('task-123');
        } finally {
            self::assertSame(2, $this->http->requestCount());
        }
    }

    public function testGetResultRetriesConnectionErrors(): void
    {
        $this->http
            ->pushConnectionError('dns failure')
            ->pushJson(self::prediction('completed'));

        $result = $this->client(maxConnectionRetries: 2)->getResult('task-123');

        self::assertSame('completed', $result['data']['status']);
        self::assertTrue($this->logger->contains('Connection error getting result on attempt 1/3'));
    }

    public function testGetResultWrapsAPersistentConnectionFailure(): void
    {
        $this->http
            ->pushConnectionError('timed out', isTimeout: true)
            ->pushConnectionError('timed out', isTimeout: true);

        try {
            $this->client(maxConnectionRetries: 1)->getResult('task-123');
            self::fail('Expected a ConnectionException.');
        } catch (ConnectionException $e) {
            self::assertSame('Failed to get result for task task-123 after 2 attempts', $e->getMessage());
            self::assertTrue($e->isTimeout);
            self::assertInstanceOf(ConnectionException::class, $e->getPrevious());
        }
    }

    public function testGetResultRejectsANonJsonBody(): void
    {
        $this->http->pushBody('<html>gateway</html>');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Failed to decode API response as JSON');

        $this->client()->getResult('task-123');
    }

    // ---------------------------------------------------------------- timeouts

    public function testRequestTimeoutsComeFromConfigAndAreCappedForConnecting(): void
    {
        Config::set(Config::TIMEOUT, 90.0);
        $this->http->pushJson(self::prediction('completed'));

        $this->client()->getResult('task-123');

        $request = $this->http->requestAt(0);
        self::assertSame(90.0, $request->timeout);
        self::assertSame(10.0, $request->connectTimeout);
    }

    public function testAnExplicitTimeoutAlsoCapsTheConnectTimeout(): void
    {
        $this->http->pushJson(self::prediction('completed'));

        $this->client()->getResult('task-123', timeout: 2.5);

        $request = $this->http->requestAt(0);
        self::assertSame(2.5, $request->timeout);
        self::assertSame(2.5, $request->connectTimeout);
    }
}
