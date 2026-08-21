<?php

declare(strict_types=1);

/**
 * Sync mode: ask the API to return the result in the submit response, and
 * recover gracefully when the server-side wait runs out.
 *
 *     WAVESPEED_API_KEY=your-key php examples/sync_mode.php
 */

require __DIR__ . '/../vendor/autoload.php';

use WaveSpeed\Client;
use WaveSpeed\Exception\SyncModeTimeoutException;

$client = new Client();

try {
    $output = $client->run(
        'wavespeed-ai/z-image/turbo',
        ['prompt' => 'A neon-lit alley in the rain'],
        enableSyncMode: true,
    );

    echo $output['outputs'][0], PHP_EOL;
} catch (SyncModeTimeoutException $e) {
    // Not a failure: the task is still processing. Poll for it.
    echo 'Sync wait expired for task ', (string) $e->taskId, '; polling instead...', PHP_EOL;

    do {
        sleep(2);
        $state = $client->getResult((string) $e->taskId);
        $status = $state['data']['status'] ?? 'unknown';
        echo '  status: ', (string) $status, PHP_EOL;
    } while (!in_array($status, ['completed', 'failed', 'cancelled', 'timeout'], true));

    if ($status === 'completed') {
        echo implode(PHP_EOL, (array) ($state['data']['outputs'] ?? [])), PHP_EOL;
    }
}
