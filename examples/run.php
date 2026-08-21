<?php

declare(strict_types=1);

/**
 * Generate an image and print the output URL.
 *
 *     WAVESPEED_API_KEY=your-key php examples/run.php "a lighthouse at dusk"
 */

require __DIR__ . '/../vendor/autoload.php';

use WaveSpeed\Client;
use WaveSpeed\Exception\WaveSpeedException;
use WaveSpeed\Support\StderrLogger;
use WaveSpeed\WaveSpeed;

$prompt = $argv[1] ?? 'A cat sitting on a windowsill';

// The default client reads WAVESPEED_API_KEY; StderrLogger surfaces retries.
WaveSpeed::setClient(new Client(logger: new StderrLogger()));

try {
    $output = WaveSpeed::run(
        'wavespeed-ai/z-image/turbo',
        ['prompt' => $prompt],
        timeout: 300.0,
    );
} catch (WaveSpeedException $e) {
    fwrite(STDERR, 'Generation failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

foreach ($output['outputs'] as $url) {
    echo $url, PHP_EOL;
}
