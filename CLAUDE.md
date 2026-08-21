# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WaveSpeed PHP SDK — a PHP port of the official
[WaveSpeed Python SDK](https://github.com/WaveSpeedAI/wavespeed-python). It is an API client only:
it submits jobs to the hosted WaveSpeed API, polls for results, and uploads files. No serverless
worker, matching the Python SDK from 2.0.0 onward.

Behavioural parity with the Python SDK is the point of this package. When changing retry rules,
error messages, or response shapes, check `wavespeed-python` first — a deliberate divergence
belongs in the README's mapping table, an accidental one is a bug.

## Commands

```bash
composer install          # install dev dependencies
composer test             # phpunit (network-free)
composer stan             # phpstan, level 8, src + tests
vendor/bin/phpunit tests/ClientTest.php
vendor/bin/phpunit --filter testRunReturnsOutputs

# Hits the real API; costs credits. Excluded from the default suite.
WAVESPEED_API_KEY=... vendor/bin/phpunit --group integration
```

## Architecture

- `src/Client.php` — the only place that talks to the API. Submission, polling, terminal-status
  handling, upload, retry policy, attribution headers.
- `src/WaveSpeed.php` — static facade over a lazily built default `Client`. `setClient()` exists so
  tests and applications can inject one.
- `src/Config.php` — process-wide defaults (the counterpart of Python's `config.api` module),
  including `patch()` as a scoped override. `timeout` is read live per call; everything else is
  captured when a `Client` is constructed.
- `src/Http/` — `HttpClient` interface plus `Request`/`Response` value objects and the cURL
  implementation. An HTTP error status is a *response*, not an exception; only the absence of a
  response throws.
- `src/Support/` — `Clock` and `Logger` seams. `SystemClock`/`NullLogger` are the defaults.
- `src/Exception/` — every type implements the `WaveSpeedException` marker interface.

## Invariants worth preserving

- **A submission POST is sent at most once.** It is not idempotent; replaying it can create a
  second charged task. `SubmissionException` is therefore never retryable. Result-query GETs are.
- **A prediction verdict is not a transport error.** `PredictionFailedException` and
  `SyncModeTimeoutException` are never retried, even when their message quotes an HTTP status
  coming from the model.
- **An unknown `data.status` means "keep polling."** The API owns that vocabulary; a new
  non-terminal state must not break the poll loop.
- **Uploads stream.** Never read the payload into a string on the way out.
- **Tests never touch the network.** Use `MockHttpClient` and `FakeClock` (see `tests/Support/`).
