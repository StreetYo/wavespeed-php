# Changelog

All notable changes to this project are documented here.

This project follows [Semantic Versioning](https://semver.org/). Versions are
published as Git tags (`v1.0.0`) and resolved at runtime through Composer.

## 1.0.0

Initial release: a PHP port of the official
[WaveSpeed Python SDK](https://github.com/WaveSpeedAI/wavespeed-python) 2.0.1,
with the same API surface and the same retry semantics.

### Added

- `WaveSpeed\Client` — job submission, result polling, terminal-status handling,
  and file upload, plus the channel-attribution headers (`X-Client-Name`,
  `X-Client-Version`, `X-Client-OS`).
- `WaveSpeed\WaveSpeed` — static entry point over a lazily created default
  client: `run()`, `runNoThrow()`, `getResult()`, `upload()`.
- `WaveSpeed\Config` — process-wide defaults with `patch()` for scoped
  overrides, the counterpart of the Python SDK's `config.api` module.
- Typed exceptions, all implementing `WaveSpeed\Exception\WaveSpeedException`,
  so callers can distinguish a submission failure from a prediction verdict,
  a sync-mode timeout, or a transport error.
- `WaveSpeed\Http\HttpClient` transport seam with a cURL implementation;
  uploads stream from disk instead of being buffered in memory.
- `Clock` and `Logger` seams: polling and backoff are testable without waiting,
  and retry diagnostics are silent until a logger is supplied.

### Notes

- A submission POST is sent at most once. It is not idempotent, so a failure
  without a response surfaces as `SubmissionException` rather than becoming a
  second charged task. Result-query GETs are retried freely.
- `maxRetries` (default 0) opts into submitting a *replacement* task after a
  confirmed transient failure — matching the Python SDK's default of no
  task-level retries.
