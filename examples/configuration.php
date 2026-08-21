<?php

declare(strict_types=1);

/**
 * Application-wide defaults, plus a per-client override.
 *
 *     WAVESPEED_API_KEY=your-key php examples/configuration.php
 */

require __DIR__ . '/../vendor/autoload.php';

use WaveSpeed\Client;
use WaveSpeed\Config;
use WaveSpeed\Support\CallableLogger;

// Set once during bootstrap; every Client built afterwards inherits it.
Config::apply([
    Config::TIMEOUT => 600.0,
    Config::MAX_CONNECTION_RETRIES => 8,
    Config::RETRY_INTERVAL => 0.5,
]);

$client = new Client(
    // Opt in to replacement tasks after a confirmed terminal failure.
    maxRetries: 1,
    // Bridge diagnostics to any PSR-3 logger.
    logger: new CallableLogger(static fn (string $message) => error_log('[wavespeed] ' . $message)),
);

echo 'timeout: ', Config::timeout(), 's, connection retries: ', $client->maxConnectionRetries, PHP_EOL;

// Temporarily change settings for one block, then restore them.
$outputs = Config::patch([Config::TIMEOUT => 60.0], static function () use ($client): array {
    return $client->run('wavespeed-ai/z-image/turbo', ['prompt' => 'A paper boat'])['outputs'];
});

echo implode(PHP_EOL, $outputs), PHP_EOL;
echo 'timeout restored to: ', Config::timeout(), 's', PHP_EOL;
