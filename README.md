# clickclacks/clickclacks-php

Send events to [ClickClacks](https://clickclacks.io) from your PHP servers: "Subscription
started", "Invoice paid", "Export finished". Server events can't be faked by a visitor,
don't depend on ad blockers, and don't need a browser open.

- Batches, gzips and retries safely. Every event carries an `insert_id`, so a retry never
  double counts.
- PHP 8.1+, with no required dependencies beyond ext-curl and ext-json. Bring a PSR-18
  client or a PSR-3 logger if you like.
- Never throws into your app: delivery problems go to a callback or your logger.
- Optional Laravel integration: facade, config, flush after the response, queued sending,
  Octane-safe.

```sh
composer require clickclacks/clickclacks-php
```

## Quick start

Create a server key in ClickClacks under **Settings › API keys › Server keys** (it starts
with `cks_live_` and is shown once). Keep it in an environment variable and never ship it
to a browser; the API refuses browser requests.

### Plain PHP

```php
use ClickClacks\Client;

$clickclacks = new Client(['key' => getenv('CLICKCLACKS_SERVER_KEY')]);

$clickclacks->track([
    'event' => 'Subscription started',
    'distinctId' => 'user_8412',
    'properties' => ['plan' => 'pro', '$revenue' => 49, '$currency' => 'USD'],
]);

$clickclacks->identify([
    'distinctId' => 'user_8412',
    'anonymousId' => 'per_k3J9sQ1xR2', // optional: the browser key, to join the browser's history
    'properties' => ['plan' => 'pro'],
]);
```

`track`, `identify` and `group` return at once; items are queued and sent in batches. The
queue is sent when it reaches `flushAt` (100) and, automatically, when the script ends
(from a shutdown function and the client's destructor). Call `$clickclacks->flush()`
yourself when you want them sent now, for example at the end of a long-running worker's
loop.

Under PHP-FPM, shutdown functions run before the connection closes, so the end-of-script
flush adds its network time to the response. Call `fastcgi_finish_request()` first (Laravel
and Symfony already do), or use [queued sending](#queued-sending-no-request-waits) in
Laravel.

### Laravel

Laravel 11 or later. The service provider and the `ClickClacks` facade are auto-discovered. Add the key to
`.env`:

```dotenv
CLICKCLACKS_SERVER_KEY=cks_live_…
```

```php
use ClickClacks\Laravel\Facades\ClickClacks;

ClickClacks::track([
    'event' => 'Invoice paid',
    'distinctId' => (string) $user->id,
    'properties' => ['amount_cents' => $invoice->amount_cents],
]);
```

That's it: calls are queued in memory and sent **after the response has been sent**, from
a `terminating` callback. They're also flushed after every queued job and console command.
Publish the config if you want to change the defaults:

```sh
php artisan vendor:publish --tag=clickclacks-config
```

Prefer injection? Type-hint `ClickClacks\ClickClacksInterface`.

#### Queued sending (no request waits)

Turn on queued mode and batches are handed to a queued job (`ClickClacks\Laravel\SendBatch`)
instead of being sent from the web process:

```dotenv
CLICKCLACKS_QUEUE=true
CLICKCLACKS_QUEUE_CONNECTION=redis   # optional
CLICKCLACKS_QUEUE_NAME=analytics     # optional
```

Or queue a single call: `ClickClacks::queue()->track([...])`. (`ClickClacks::now()` goes the
other way: it sends from this process even when queued mode is on.) Items get their
`insert_id` before they're queued, so a job that runs twice never double counts. When a
batch fails for a retryable reason (network, `429`, `5xx`), the job re-dispatches only the
items that failed, with a growing delay, up to `queue_tries` (3) times.

#### Octane and queue workers

The client is a singleton that holds nothing but the items waiting to be sent, and they're
sent at the end of every request (Octane's `RequestTerminated`, `TaskTerminated` and
`TickTerminated` too), job and command. No user, request or tenant state is kept between
them.

## Examples: a CRM

Say your product is a CRM, and one of your customers is a company called **Jay's
Plumbing**, with employees who log in. People are identified by your user ID; the company
is a group.

```php
// When Jay's Plumbing signs up, or changes plan: record the company's traits.
// The newest call replaces the whole set, so send every trait you want shown.
$clickclacks->group([
    'groupType' => 'company',
    'groupId' => 'cmp_311',            // your company ID, not a name or a domain
    'properties' => [
        'name' => "Jay's Plumbing",
        'plan' => 'pro',
        'seats' => 12,
        'industry' => 'Trades',
    ],
]);

// When an employee signs up or their profile changes: record the person's traits.
$clickclacks->identify([
    'distinctId' => 'user_8412',       // your user ID
    'properties' => ['role' => 'owner', 'company_id' => 'cmp_311'],
]);
$clickclacks->identify([
    'distinctId' => 'user_8413',
    'properties' => ['role' => 'dispatcher', 'company_id' => 'cmp_311'],
]);

// Events count for the company when they carry it in `groups`.
$clickclacks->track([
    'event' => 'Job scheduled',
    'distinctId' => 'user_8413',
    'properties' => ['job_type' => 'boiler service', 'value_cents' => 18000],
    'groups' => ['company' => 'cmp_311'],
]);

$clickclacks->track([
    'event' => 'Invoice paid',
    'distinctId' => 'user_8412',
    'insertId' => 'inv_2291',          // your invoice ID: a webhook retried twice still counts once
    'properties' => ['$revenue' => 180, '$currency' => 'USD'],
    'groups' => ['company' => 'cmp_311'],
]);
```

In Laravel, the same calls go through the facade: `ClickClacks::group([...])`.

## API

### `new ClickClacks\Client(array $options)`

| Option | Default | |
|---|---|---|
| `key` | required | A secret server key, `cks_live_…` (legacy `sk_live_…` Source keys also work). A public `pk_live_` key throws. |
| `host` | `https://app.clickclacks.io` | Custom domains don't serve the server API. |
| `flushAt` | `100` | Items per batch, 1–500. Reaching it sends at once. |
| `maxQueueSize` | `10000` | Items held in memory. When full, new items are dropped. |
| `maxRetries` | `6` | Retries after the first attempt. |
| `requestTimeout` | `10000` | Milliseconds before one request is abandoned and retried. |
| `shutdownTimeout` | `10000` | Milliseconds `shutdown()` (and the end-of-script flush) keeps retrying. |
| `sync` | `false` | Send every call before `track`/`identify`/`group` returns. |
| `validate` | `false` | Dry run: send `?validate=true`. Nothing is stored or counted; `FlushResult::$validated` holds the items as they'd be stored. |
| `strict` | `false` | Send `?strict=true`: the API refuses the whole batch when any item is invalid. Useful in CI. |
| `gzip` | `true` | Gzip bodies over 1 KiB (needs ext-zlib). |
| `autoFlush` | `true` | Flush when the script ends and when the client is destroyed. |
| `onError` | log | `fn (ClickClacks\ClickClacksError $error)`. Without it, errors go to `logger`, or to `error_log()`. |
| `onWarning` | log | `fn (array $warnings)`, a list of `ClickClacks\ItemWarning`: items the API accepted with a change. |
| `logger` | none | A PSR-3 logger. Errors are logged as `warning`, API warnings as `info`. |
| `throwOnError` | `false` | Throw `ClickClacksError` instead of reporting it. Bad calls throw at once; delivery errors throw from `flush()` after every batch was tried. For tests and scripts. |
| `httpClient` | cURL | A PSR-18 client. PSR-17 factories are found for Guzzle and Nyholm, or pass `requestFactory` and `streamFactory`. |
| `transport` | cURL | Your own `ClickClacks\Transport\Transport`. |

There's no `flushInterval`: PHP has no timers between requests, so the queue is sent when
it's full, when you call `flush()`, and when the script ends.

### `track(array $params): void`

`event`, and one of `distinctId` (your user ID) or `anonymousId` (a `per_…` browser key),
are required. Optional: `sessionId` (a `ses_…` ID), `timestamp` (a `DateTimeInterface`, an
ISO 8601 string or epoch milliseconds; defaults to the moment you call `track`),
`insertId` (1–80 characters of `[A-Za-z0-9_-]`, generated when omitted), `properties` and
`groups`. Keys are camelCase, like the Node SDK; an unknown key is an `invalid_call`, so a
typo like `distinct_id` doesn't go unnoticed.

Event names starting with `$` are reserved. In `properties` you may send `$ip`,
`$user_agent`, `$country`, `$current_url`, `$groups`, `$revenue` and `$currency`; other
`$` keys are refused by the API.

`groups` says which groups the event counts for, as `['groupType' => 'groupId']`, at most
5 entries. The SDK writes it into `properties.$groups`; when both are sent, `groups` wins.

### `identify(array $params): void`

Records traits for a user, and links `anonymousId` (if given) to them, exactly like the
browser tracker's `identify`. Takes `distinctId` (required), `anonymousId`, `timestamp`,
`insertId` and `properties`. `identify` calls are free.

### `group(array $params): void`

Records traits for a group, such as a company, workspace or team. It sends one `group`
item, which the API stores as a free `$group_identify` event; it has no person.

- `groupType` is 1–64 characters of `[a-z0-9_]`, for example `company`. A Project has at
  most 5 group types.
- `groupId` is your ID for the group, 1–255 characters, trimmed, with no control
  characters. An integer is sent as a string. Use an opaque ID (`cmp_311`), not a domain
  name or an email address.
- The newest `group` call replaces the group's whole trait set. Traits that look personal
  (an email address, a phone number, or keys such as `email`, `phone`, `ip`, `address`,
  `password`) are dropped by the API unless the Source allows them; each drop comes back as
  a `group_trait_dropped` warning.
- `group` records the profile only. An event counts for a group when it carries the group:
  pass `groups` to `track`.

Group support is rolling out on the API. Until it reaches your project, a `group` item is
refused with the per-item code `item_type_not_yet_supported`, which reaches `onError` as
an `item_errors` error; nothing else in the batch is affected.

### `flush(?int $timeout = null): FlushResult`

Sends everything queued now and returns when it's delivered, refused or given up on. With
`$timeout` (milliseconds) it stops retrying at the deadline and reports what's left as
`flush_timeout`. It never throws (unless `throwOnError` is on).

`FlushResult` has `sent`, `accepted`, `failed`, `itemErrors`, `warnings`, `dropped` (items
set aside by the Source's policy, such as `bot_filtered`), `errors`, and with `validate`,
`valid` and `validated`. `ok()` is true when nothing was refused or lost.

### `shutdown(?int $timeout = null): FlushResult`

Flushes with a deadline (default `shutdownTimeout`) and then refuses new calls
(`client_closed`). Safe to call twice.

### Errors

`onError` receives a `ClickClacks\ClickClacksError` (a `RuntimeException`) with a string
`$errorCode`:

| `errorCode` | Meaning |
|---|---|
| `item_errors` | The API refused some items. `$itemErrors` lists `ItemError { index, code, field, message, insertId, event }`. Never retried. |
| `request_rejected` | The API refused the whole request (for example `invalid_key`). `$apiCode` and `$status` say why. Never retried. |
| `request_failed` | Every retry failed; `$count` items were lost. |
| `queue_full` | `maxQueueSize` was reached and a new item was dropped. `$dropped` is the running count. |
| `shutdown_timeout` | `shutdown()` hit its deadline; `$count` items weren't delivered. |
| `flush_timeout` | `flush($timeout)` hit its deadline; `$count` items weren't delivered. |
| `invalid_call` | A `track`/`identify`/`group` call was malformed; nothing was queued. |
| `item_too_large` | One item serialised to more than 1 MiB. |
| `client_closed` | A call arrived after `shutdown()`. |

`$requestId` carries the API's `request_id` for support. The key never appears in an error
or a log line.

`onWarning` receives the API's `warnings` for items it accepted with a change, as a list of
`ItemWarning { index, code, field, message, insertId, event }`, for example
`group_trait_dropped` on `properties.email`. Warnings are not errors, and nothing is
retried.

## How delivery works

- **Batching:** a batch is sent when the queue reaches `flushAt`, on `flush()`, and when
  the script ends, split so each request stays under the API's 500 items and 1 MiB.
  Batches are sent one at a time, in order.
- **Compression:** bodies over 1 KiB are gzipped.
- **Idempotency:** every item gets an `insert_id` (32 random hex characters) when it's
  queued, and a retry resends the same bytes. The API drops repeats within a Source, so
  retrying is always safe. Pass your own `insertId` (an invoice or webhook ID) to make
  your own retries safe too.
- **Retries:** network errors, timeouts, `408`, `429` and `5xx` are retried with exponential
  backoff (500 ms × 2ⁿ, capped at 30 s) and full jitter. A server `Retry-After` always wins,
  up to 5 minutes. Other `4xx` responses are never retried. A `413` splits the batch in
  half and resends.
- **Blocking:** PHP waits for the network, so a retry holds up the process that flushes.
  Keep flushes off the request path: flush after the response (Laravel does), use queued
  sending, or give `flush()` a timeout.
- **Queue cap:** when `maxQueueSize` is reached, *new* items are dropped, so an outage loses
  the tail rather than events already queued.

Rate limits are 1,000 events/s sustained and 5,000 events/s burst per project, and 100
requests/s per key. A `429` means nothing in that request was stored, so the retry is safe.

## Privacy

- Never send personal data you don't need. Use an opaque internal user ID as
  `distinctId`, not an email address; put an email or name only in `identify` traits, and
  only if you need them.
- Use opaque group IDs (`cmp_311`), not domains or email addresses. Personal-looking group
  traits are dropped by default.
- Never put secrets, tokens or passwords in properties.
- `$ip` is kept only when the Source records IP addresses; `$user_agent` is used for
  browser/OS/device and bot filtering, then dropped.

## Testing your code

Outside Laravel, depend on `ClickClacks\ClickClacksInterface` and pass
`ClickClacks\Testing\FakeClient` in tests. It sends nothing and records every call:

```php
use ClickClacks\Testing\FakeClient;

$clickclacks = new FakeClient();
(new Billing($clickclacks))->pay($invoice);

$clickclacks->assertTracked('Invoice paid', fn (array $call) => $call['properties']['$revenue'] === 180);
$clickclacks->assertGrouped('company', 'cmp_311');
$clickclacks->assertNothingTracked(); // or inspect $clickclacks->tracked
```

In Laravel, `ClickClacks::fake()` swaps the facade (and `ClickClacksInterface`) for the fake:

```php
ClickClacks::fake();

$this->post('/invoices/2291/pay')->assertOk();

ClickClacks::assertTracked('Invoice paid');
ClickClacks::assertIdentified('user_8412');
```

To check your events against the real API without storing anything, run a client with
`'validate' => true` (and `'strict' => true` to fail on any invalid item) and read the
`FlushResult`. Set `CLICKCLACKS_ENABLED=false` to turn sending off in local development;
with no key set, calls are accepted and dropped.

## Versioning

The SDK follows [Semantic Versioning](https://semver.org/). The server API is versioned in
its path (`/api/v1/`) and only changes additively within v1, and so does this SDK within a
major version. Releases are listed in [CHANGELOG.md](CHANGELOG.md).

## Development

```sh
composer install
composer test        # PHPUnit: unit, contract fixtures and Laravel (Testbench)
composer analyse     # PHPStan level 8
composer lint        # php-cs-fixer (PER-CS 2.0)
composer fixtures:sync -- ../clickclacks   # copy the API's contract fixtures into tests/fixtures/server
```

`tests/Unit/ContractTest.php` replays the API's request and response fixtures through the
client: the examples transcribed from the API spec in `tests/fixtures/spec/`, and the API's
own contract fixtures in `tests/fixtures/server/`.

The full API reference, including every error code, is at
[clickclacks.io/docs/api](https://clickclacks.io/docs/api). The Node SDK is
[`@clickclacks/node`](https://github.com/dimitrikhoury47/clickclacks-node).

## Licence

MIT
