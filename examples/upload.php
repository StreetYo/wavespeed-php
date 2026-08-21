<?php

declare(strict_types=1);

/**
 * Upload a local image, then use its URL as model input.
 *
 *     WAVESPEED_API_KEY=your-key php examples/upload.php /path/to/image.png
 */

require __DIR__ . '/../vendor/autoload.php';

use WaveSpeed\Client;

$path = $argv[1] ?? null;
if ($path === null) {
    fwrite(STDERR, 'Usage: php examples/upload.php /path/to/image.png' . PHP_EOL);
    exit(1);
}

$client = new Client();

// Large files stream straight from disk — nothing is buffered in memory.
$imageUrl = $client->upload($path);
echo 'Uploaded: ', $imageUrl, PHP_EOL;

$output = $client->run(
    'wavespeed-ai/z-image/turbo',
    ['prompt' => 'Turn this into a watercolour painting', 'image' => $imageUrl],
    timeout: 300.0,
);

echo $output['outputs'][0], PHP_EOL;
