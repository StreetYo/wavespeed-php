<?php

declare(strict_types=1);

/**
 * Branch on the outcome instead of catching exceptions — handy in a queue
 * worker that records the task ID and moves on.
 *
 *     WAVESPEED_API_KEY=your-key php examples/run_no_throw.php
 */

require __DIR__ . '/../vendor/autoload.php';

use WaveSpeed\Client;
use WaveSpeed\PredictionStatus;

$client = new Client();

$result = $client->runNoThrow(
    'wavespeed-ai/z-image/turbo',
    ['prompt' => 'A quiet harbour at dawn'],
    timeout: 300.0,
);

switch ($result['status']) {
    case PredictionStatus::Completed->value:
        echo 'Done: ', implode(', ', (array) $result['outputs']), PHP_EOL;
        break;

    case PredictionStatus::Processing->value:
        // Still running server-side: keep the ID and collect it later.
        echo 'Still running, task ', $result['task_id'], PHP_EOL;
        echo 'Recover it with: $client->getResult(', var_export($result['task_id'], true), ')', PHP_EOL;
        break;

    default:
        fwrite(STDERR, 'Failed (' . $result['task_id'] . '): ' . (string) $result['error'] . PHP_EOL);
        exit(1);
}
