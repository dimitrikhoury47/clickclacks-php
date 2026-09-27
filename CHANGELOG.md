# Changelog

This project follows [Semantic Versioning](https://semver.org/). Within the v1 API, the
server only makes additive changes, and so does this SDK within a major version.

## 1.0.0

First release, for `POST /api/v1/batch`. The PHP twin of `@clickclacks/node` 1.1.0.

- `track`, `identify` and `group`, queued and sent in batches of `flushAt` (default 100,
  at most 500) and when the script ends, through `register_shutdown_function` and the
  client's destructor.
- `group(['groupType' => …, 'groupId' => …, 'properties' => …])` records a group's traits
  (the newest call replaces the whole set). `track` takes a `groups` option, written into
  `properties.$groups`.
- Every item gets an `insert_id` when it's queued, so retries never double count. A given
  `insertId` is checked against the API's rule (1–80 characters of `[A-Za-z0-9_-]`).
- Bodies over 1 KiB are gzipped; each request stays under the API's 1 MiB cap.
- Retries on network errors, timeouts, `408`, `429` and `5xx`: exponential backoff
  (500 ms × 2ⁿ, capped at 30 s) with full jitter, and a server `Retry-After` always wins
  (up to 5 minutes). Other `4xx` responses are never retried. A `413` splits the batch.
- Per-item errors go to `onError` with the item's `insert_id`; the API's `warnings` go to
  `onWarning`. Neither is retried.
- Never throws into your app for bad input or delivery problems: they go to `onError`, a
  PSR-3 `logger`, or `error_log`. `throwOnError` turns that around for tests and scripts.
- `flush()` and `shutdown()` return a `FlushResult`. `flush($timeout)` and `shutdown()`
  (default 10 s) stop retrying at a deadline.
- `sync` mode sends every call before it returns. `validate` and `strict` send
  `?validate=true` and `?strict=true`.
- cURL by default, or any PSR-18 client (`httpClient`), or your own `Transport`.
- Laravel: auto-discovered service provider, `ClickClacks` facade, `config/clickclacks.php`,
  a flush after every response, console command and queued job (Octane-safe), queued
  sending (`ClickClacks::queue()` or `CLICKCLACKS_QUEUE=true`) and `ClickClacks::fake()`.
- `ClickClacks\Testing\FakeClient` for tests outside Laravel.
- PHP 8.1+, no required dependencies beyond ext-curl and ext-json.
