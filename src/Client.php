<?php

declare(strict_types=1);

namespace ClickClacks;

use ClickClacks\Transport\CurlTransport;
use ClickClacks\Transport\HttpRequest;
use ClickClacks\Transport\Psr18Transport;
use ClickClacks\Transport\Transport;
use ClickClacks\Transport\TransportException;

/**
 * The ClickClacks server-side client. Queue calls with `track`, `identify` and `group`; they
 * are batched, gzipped and retried safely, and flushed automatically when the queue reaches
 * `flushAt` and when the script ends.
 *
 * ```php
 * $clickclacks = new ClickClacks\Client(['key' => getenv('CLICKCLACKS_SERVER_KEY')]);
 * $clickclacks->track(['event' => 'Invoice paid', 'distinctId' => 'user_8412']);
 * ```
 */
final class Client implements ClickClacksInterface
{
    public const VERSION = '1.0.0';
    public const DEFAULT_HOST = 'https://app.clickclacks.io';
    public const BATCH_PATH = '/api/v1/batch';
    /** The API's cap is 500 items per request. */
    public const MAX_BATCH_ITEMS = 500;
    /** The API's cap is 1 MiB uncompressed; stay a little under it. */
    public const MAX_BODY_BYTES = 1_000_000;
    /** Bodies over 1 KiB are gzipped. */
    public const GZIP_THRESHOLD_BYTES = 1024;
    /** Backoff: 500 ms × 2ⁿ, capped at 30 s, with full jitter. */
    public const BACKOFF_BASE_MS = 500;
    public const BACKOFF_CAP_MS = 30_000;
    /** A server `Retry-After` wins, up to 5 minutes. */
    public const RETRY_AFTER_CAP_MS = 300_000;

    private const ENVELOPE_BYTES = 12; // strlen('{"items":[]}')
    private const MAX_GROUPS_PER_EVENT = 5;
    private const GROUP_TYPE_PATTERN = '/^[a-z0-9_]{1,64}$/D';
    private const MAX_GROUP_ID_LENGTH = 255;
    private const INSERT_ID_PATTERN = '/^[A-Za-z0-9_-]{1,80}$/D';
    private const CONTROL_CHARACTERS = '/[\x{0000}-\x{001f}\x{007f}-\x{009f}]/u';
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    private const OPTION_KEYS = [
        'key', 'host', 'flushAt', 'maxQueueSize', 'maxRetries', 'requestTimeout', 'shutdownTimeout',
        'sync', 'validate', 'strict', 'gzip', 'autoFlush', 'throwOnError', 'onError', 'onWarning', 'logger',
        'transport', 'httpClient', 'requestFactory', 'streamFactory', 'dispatch', 'sleep', 'clock', 'random',
    ];

    public readonly string $host;
    public readonly int $flushAt;
    public readonly int $maxQueueSize;
    public readonly int $maxRetries;
    /** Milliseconds before one request is abandoned and retried. */
    public readonly int $requestTimeout;
    /** Milliseconds `shutdown()` keeps delivering before it gives up. */
    public readonly int $shutdownTimeout;
    /** Every call is sent at once, before `track`/`identify`/`group` return. */
    public readonly bool $sync;
    /** Dry run: `?validate=true`. Nothing is stored or metered. */
    public readonly bool $validate;
    /** `?strict=true`: the API refuses the whole batch when any item is invalid. */
    public readonly bool $strict;

    private readonly string $key;
    private readonly string $url;
    private readonly bool $gzip;
    private readonly bool $throwOnError;
    private readonly Transport $transport;
    /** @var (\Closure(ClickClacksError): void)|null */
    private readonly ?\Closure $onError;
    /** @var (\Closure(list<ItemWarning>): void)|null */
    private readonly ?\Closure $onWarning;
    private readonly ?object $logger;
    /** @var (\Closure(list<string>): void)|null */
    private readonly ?\Closure $dispatch;
    /** @var \Closure(int): void */
    private readonly \Closure $sleep;
    /** @var \Closure(): float */
    private readonly \Closure $clock;
    /** @var \Closure(): float */
    private readonly \Closure $random;

    /** @var list<array{json: string, bytes: int, insertId: string, event: string}> */
    private array $queue = [];
    private int $dropped = 0;
    private bool $closed = false;
    private bool $flushing = false;

    /** Clients to flush when the script ends, held weakly so they can still be destroyed. */
    /** @var array<int, \WeakReference<self>> */
    private static array $live = [];
    private static bool $shutdownRegistered = false;

    /**
     * @param array<string, mixed> $options see the README's configuration table
     */
    public function __construct(array $options)
    {
        $unknown = array_diff(array_keys($options), self::OPTION_KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('ClickClacks: unknown option `' . implode('`, `', $unknown) . '`');
        }

        $key = $options['key'] ?? null;
        if (!\is_string($key) || trim($key) === '') {
            throw new \InvalidArgumentException('ClickClacks: `key` is required (a cks_live_… server key)');
        }
        if (str_starts_with(trim($key), 'pk_')) {
            throw new \InvalidArgumentException('ClickClacks: that is a public browser key. Use a secret server key (cks_live_…)');
        }
        $this->key = trim($key);

        $host = $options['host'] ?? self::DEFAULT_HOST;
        if (!\is_string($host)) {
            throw new \InvalidArgumentException('ClickClacks: `host` must be an absolute http(s) URL');
        }
        $host = rtrim(trim($host), '/');
        $scheme = parse_url($host, PHP_URL_SCHEME);
        $hostname = parse_url($host, PHP_URL_HOST);
        if (!\in_array(\is_string($scheme) ? strtolower($scheme) : null, ['http', 'https'], true) || !\is_string($hostname) || $hostname === '') {
            throw new \InvalidArgumentException('ClickClacks: `host` must be an absolute http(s) URL');
        }
        $this->host = $host;

        $this->flushAt = self::intOption($options, 'flushAt', 100, 1, self::MAX_BATCH_ITEMS);
        $this->maxQueueSize = self::intOption($options, 'maxQueueSize', 10_000, 1);
        $this->maxRetries = self::intOption($options, 'maxRetries', 6, 0);
        $this->requestTimeout = self::intOption($options, 'requestTimeout', 10_000, 1);
        $this->shutdownTimeout = self::intOption($options, 'shutdownTimeout', 10_000, 0);
        $this->sync = self::boolOption($options, 'sync', false);
        $this->validate = self::boolOption($options, 'validate', false);
        $this->strict = self::boolOption($options, 'strict', false);
        $this->gzip = self::boolOption($options, 'gzip', true) && \function_exists('gzencode');
        $this->throwOnError = self::boolOption($options, 'throwOnError', false);

        $query = array_filter(['validate' => $this->validate ? 'true' : null, 'strict' => $this->strict ? 'true' : null]);
        $this->url = $host . self::BATCH_PATH . ($query === [] ? '' : '?' . http_build_query($query));

        $this->onError = self::closureOption($options, 'onError');
        $this->onWarning = self::closureOption($options, 'onWarning');
        $this->dispatch = self::closureOption($options, 'dispatch');
        $logger = $options['logger'] ?? null;
        if ($logger !== null && !($logger instanceof \Psr\Log\LoggerInterface)) {
            throw new \InvalidArgumentException('ClickClacks: `logger` must be a PSR-3 LoggerInterface');
        }
        $this->logger = $logger;

        $this->transport = self::makeTransport($options);
        $this->sleep = self::closureOption($options, 'sleep') ?? static function (int $ms): void {
            if ($ms > 0) {
                usleep($ms * 1000);
            }
        };
        $this->clock = self::closureOption($options, 'clock') ?? static fn(): float => microtime(true) * 1000;
        $this->random = self::closureOption($options, 'random') ?? static fn(): float => mt_rand() / (mt_getrandmax() + 1);

        if (self::boolOption($options, 'autoFlush', true)) {
            self::$live[spl_object_id($this)] = \WeakReference::create($this);
            if (!self::$shutdownRegistered) {
                self::$shutdownRegistered = true;
                register_shutdown_function([self::class, 'flushAllOnShutdown']);
            }
        }
    }

    public function __destruct()
    {
        unset(self::$live[spl_object_id($this)]);
        if (!$this->closed && $this->queue !== []) {
            $this->safely(fn() => $this->shutdown());
        }
    }

    /**
     * Flushes every live client when the script ends. Registered once, by the first client
     * created with `autoFlush` (the default).
     *
     * @internal
     */
    public static function flushAllOnShutdown(): void
    {
        foreach (self::$live as $id => $reference) {
            unset(self::$live[$id]);
            $client = $reference->get();
            if ($client !== null && !$client->closed && $client->queue !== []) {
                $client->safely(fn() => $client->shutdown());
            }
        }
    }

    public function pending(): int
    {
        return \count($this->queue);
    }

    /** Items dropped because the queue was full, since the client was created. */
    public function droppedCount(): int
    {
        return $this->dropped;
    }

    public function track(array $params): void
    {
        try {
            self::allowKeys($params, 'track', ['event', 'distinctId', 'anonymousId', 'sessionId', 'timestamp', 'insertId', 'properties', 'groups']);
            $event = $params['event'] ?? null;
            if (!\is_string($event) || trim($event) === '') {
                throw new \InvalidArgumentException('track needs an `event` name');
            }
            $distinctId = self::optionalId($params['distinctId'] ?? null, 'distinctId');
            $anonymousId = self::optionalId($params['anonymousId'] ?? null, 'anonymousId');
            if (($distinctId ?? '') === '' && ($anonymousId ?? '') === '') {
                throw new \InvalidArgumentException('track needs a `distinctId` or an `anonymousId`');
            }
            $properties = self::wireProperties($params['properties'] ?? null);
            $groups = self::wireGroups($params['groups'] ?? null);
            if ($groups !== null) {
                // The option wins over `properties.$groups`.
                $properties = [...($properties ?? []), '$groups' => $groups];
            }
            $item = [
                'type' => 'track',
                'event' => $event,
                'distinct_id' => $distinctId,
                'anonymous_id' => $anonymousId,
                'session_id' => self::optionalId($params['sessionId'] ?? null, 'sessionId'),
                'timestamp' => self::wireTimestamp($params['timestamp'] ?? null),
                'insert_id' => self::insertId($params['insertId'] ?? null),
                'properties' => $properties,
            ];
        } catch (\InvalidArgumentException $error) {
            $this->invalid($error);

            return;
        }
        $this->enqueue($item, $event);
    }

    public function identify(array $params): void
    {
        try {
            self::allowKeys($params, 'identify', ['distinctId', 'anonymousId', 'timestamp', 'insertId', 'properties']);
            $distinctId = self::optionalId($params['distinctId'] ?? null, 'distinctId');
            if (($distinctId ?? '') === '') {
                throw new \InvalidArgumentException('identify needs a `distinctId`');
            }
            $item = [
                'type' => 'identify',
                'distinct_id' => $distinctId,
                'anonymous_id' => self::optionalId($params['anonymousId'] ?? null, 'anonymousId'),
                'timestamp' => self::wireTimestamp($params['timestamp'] ?? null),
                'insert_id' => self::insertId($params['insertId'] ?? null),
                'properties' => self::wireProperties($params['properties'] ?? null),
            ];
        } catch (\InvalidArgumentException $error) {
            $this->invalid($error);

            return;
        }
        $this->enqueue($item, '$identify');
    }

    public function group(array $params): void
    {
        try {
            self::allowKeys($params, 'group', ['groupType', 'groupId', 'properties', 'timestamp', 'insertId']);
            $item = [
                'type' => 'group',
                'group_type' => self::groupType($params['groupType'] ?? null, 'group `groupType`'),
                'group_id' => self::groupId($params['groupId'] ?? null, 'group `groupId`'),
                'timestamp' => self::wireTimestamp($params['timestamp'] ?? null),
                'insert_id' => self::insertId($params['insertId'] ?? null),
                'properties' => self::wireProperties($params['properties'] ?? null),
            ];
        } catch (\InvalidArgumentException $error) {
            $this->invalid($error);

            return;
        }
        $this->enqueue($item, '$group_identify');
    }

    public function flush(?int $timeout = null): FlushResult
    {
        return $this->flushQueue($timeout, ClickClacksError::FLUSH_TIMEOUT);
    }

    public function shutdown(?int $timeout = null): FlushResult
    {
        $result = $this->flushQueue($timeout ?? $this->shutdownTimeout, ClickClacksError::SHUTDOWN_TIMEOUT);
        $this->closed = true;
        unset(self::$live[spl_object_id($this)]);

        return $result;
    }

    /** Whether `shutdown()` was called. */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Sends items that were prepared earlier (by a queued `dispatch`, for example a Laravel
     * queue job) over HTTP now, with the usual batching, retries and error reporting. Each
     * item is the JSON of one wire item and keeps its `insert_id`, so re-sending is safe.
     *
     * @param list<string> $items
     * @param bool         $reportFailures false leaves `request_failed` and timeouts out of
     *                                     `onError`; the caller reads `FlushResult::$retryable`
     *                                     and retries later
     */
    public function sendPrepared(array $items, ?int $timeout = null, bool $reportFailures = true): FlushResult
    {
        $queued = [];
        foreach ($items as $json) {
            $decoded = \is_string($json) ? json_decode($json, true) : null;
            if (!\is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
                continue;
            }
            $queued[] = [
                'json' => $json,
                'bytes' => \strlen($json),
                'insertId' => \is_string($decoded['insert_id'] ?? null) ? $decoded['insert_id'] : '',
                'event' => self::eventOf($decoded),
            ];
        }
        $run = new Delivery($this->deadline($timeout), ClickClacksError::FLUSH_TIMEOUT, $reportFailures);
        $this->sendAll($queued, $run, false);

        return $this->finish($run);
    }

    // ---------------------------------------------------------------------------------
    // Queueing

    /**
     * @param array<string, mixed> $item
     */
    private function enqueue(array $item, string $event): void
    {
        if ($this->closed) {
            $this->report(new ClickClacksError(ClickClacksError::CLIENT_CLOSED, 'The client was shut down; the item was not queued', count: 1));

            return;
        }
        $item = array_filter($item, static fn($value) => $value !== null);
        if (isset($item['properties']) && $item['properties'] === []) {
            $item['properties'] = new \stdClass();
        }
        try {
            $json = json_encode($item, self::JSON_FLAGS);
        } catch (\JsonException $error) {
            $this->invalid(new \InvalidArgumentException('properties are not JSON-serialisable: ' . $error->getMessage(), 0, $error));

            return;
        }
        $bytes = \strlen($json);
        if ($bytes > self::MAX_BODY_BYTES - self::ENVELOPE_BYTES) {
            $this->report(new ClickClacksError(ClickClacksError::ITEM_TOO_LARGE, "An item is {$bytes} bytes, over the 1 MiB request cap", count: 1));

            return;
        }
        if (\count($this->queue) >= $this->maxQueueSize) {
            ++$this->dropped;
            $this->report(new ClickClacksError(
                ClickClacksError::QUEUE_FULL,
                "The queue holds {$this->maxQueueSize} items; the new item was dropped ({$this->dropped} dropped so far)",
                count: 1,
                dropped: $this->dropped,
            ));

            return;
        }
        /** @var string $insertId */
        $insertId = $item['insert_id'];
        $this->queue[] = ['json' => $json, 'bytes' => $bytes, 'insertId' => $insertId, 'event' => $event];
        if ($this->sync || \count($this->queue) >= $this->flushAt) {
            $this->flush();
        }
    }

    private function flushQueue(?int $timeout, string $timeoutCode): FlushResult
    {
        if ($this->flushing) {
            // A flush from inside onError/onWarning; the running flush picks the items up.
            return new FlushResult();
        }
        $this->flushing = true;
        $run = new Delivery($this->deadline($timeout), $timeoutCode, true);
        try {
            // Items queued by a handler while this flush runs are sent by it too.
            while ($this->queue !== []) {
                $items = $this->queue;
                $this->queue = [];
                $this->sendAll($items, $run, $this->dispatch !== null);
            }
        } catch (\Throwable $error) {
            $this->collect($run, new ClickClacksError(ClickClacksError::REQUEST_FAILED, 'Unexpected client error: ' . $error->getMessage(), previous: $error));
        } finally {
            $this->flushing = false;
        }

        return $this->finish($run);
    }

    private function finish(Delivery $run): FlushResult
    {
        $result = $run->result();
        if ($this->throwOnError && $result->errors !== []) {
            throw $result->errors[0];
        }

        return $result;
    }

    private function deadline(?int $timeout): ?float
    {
        if ($timeout === null) {
            return null;
        }

        return ($this->clock)() + max(0, $timeout);
    }

    // ---------------------------------------------------------------------------------
    // Delivery

    /**
     * Splits into batches of ≤ flushAt items and ≤ 1 MiB, then sends them in order.
     *
     * @param list<array{json: string, bytes: int, insertId: string, event: string}> $items
     */
    private function sendAll(array $items, Delivery $run, bool $dispatch): void
    {
        $batches = [];
        $current = [];
        $currentBytes = self::ENVELOPE_BYTES;
        foreach ($items as $item) {
            $added = $item['bytes'] + ($current !== [] ? 1 : 0);
            if ($current !== [] && (\count($current) >= $this->flushAt || $currentBytes + $added > self::MAX_BODY_BYTES)) {
                $batches[] = $current;
                $current = [];
                $currentBytes = self::ENVELOPE_BYTES;
                $added = $item['bytes'];
            }
            $currentBytes += $added;
            $current[] = $item;
        }
        if ($current !== []) {
            $batches[] = $current;
        }

        foreach ($batches as $i => $batch) {
            if ($dispatch && $this->dispatch !== null) {
                $this->dispatchBatch($batch, $run);
                continue;
            }
            if ($this->sendBatch($batch, $run) === 'aborted') {
                $left = \array_slice($batches, $i);
                $count = array_sum(array_map('count', $left));
                $run->failed += $count;
                foreach ($left as $rest) {
                    foreach ($rest as $item) {
                        $run->retryable[] = $item['json'];
                    }
                }
                $label = $run->timeoutCode === ClickClacksError::SHUTDOWN_TIMEOUT ? 'shutdown()' : 'flush()';
                if ($run->reportFailures) {
                    $this->collect($run, new ClickClacksError($run->timeoutCode, "{$label} timed out; {$count} items were not delivered", count: $count));
                }

                return;
            }
        }
    }

    /**
     * @param list<array{json: string, bytes: int, insertId: string, event: string}> $batch
     */
    private function dispatchBatch(array $batch, Delivery $run): void
    {
        $jsons = array_map(static fn(array $item): string => $item['json'], $batch);
        try {
            \assert($this->dispatch !== null);
            ($this->dispatch)($jsons);
            $run->sent += \count($batch);
        } catch (\Throwable $error) {
            $run->failed += \count($batch);
            array_push($run->retryable, ...$jsons);
            $this->collect($run, new ClickClacksError(
                ClickClacksError::REQUEST_FAILED,
                'Could not hand ' . \count($batch) . ' items to the queue: ' . $error->getMessage(),
                count: \count($batch),
                previous: $error,
            ));
        }
    }

    /**
     * @param list<array{json: string, bytes: int, insertId: string, event: string}> $batch
     *
     * @return 'done'|'aborted'
     */
    private function sendBatch(array $batch, Delivery $run): string
    {
        $body = '{"items":[' . implode(',', array_map(static fn(array $item): string => $item['json'], $batch)) . ']}';
        $headers = [
            'Authorization' => 'Bearer ' . $this->key,
            'Content-Type' => 'application/json',
            'User-Agent' => 'clickclacks-php/' . self::VERSION . ' php/' . PHP_VERSION,
        ];
        $payload = $body;
        if ($this->gzip && \strlen($body) > self::GZIP_THRESHOLD_BYTES) {
            $compressed = gzencode($body);
            if (\is_string($compressed)) {
                $payload = $compressed;
                $headers['Content-Encoding'] = 'gzip';
            }
        }

        $lastStatus = null;
        $lastBody = null;
        $lastError = null;
        for ($attempt = 0; $attempt <= $this->maxRetries; ++$attempt) {
            $remaining = $run->remaining($this->clock);
            if ($remaining !== null && $remaining <= 0) {
                return 'aborted';
            }
            $timeout = $remaining === null ? $this->requestTimeout : min($this->requestTimeout, $remaining);
            $response = null;
            try {
                $response = $this->transport->send(new HttpRequest($this->url, $headers, $payload, $timeout / 1000));
            } catch (TransportException $error) {
                $lastError = $error;
                $lastStatus = null;
                $lastBody = null;
            } catch (\Throwable $error) {
                // A custom transport that threw something else: treat it as a network error.
                $lastError = $error;
                $lastStatus = null;
                $lastBody = null;
            }

            if ($response === null) {
                if ($attempt < $this->maxRetries && !$this->wait($this->backoffDelay($attempt), $run)) {
                    return 'aborted';
                }
                continue;
            }

            $status = $response->status;
            $parsed = self::parseJson($response->body);
            if ($status >= 200 && $status < 300) {
                $run->sent += \count($batch);
                $this->readSuccess($batch, $parsed, $status, $run);

                return 'done';
            }
            if ($status === 413 && \count($batch) > 1) {
                // The API measured the body over its cap: split and resend.
                $half = (int) ceil(\count($batch) / 2);
                if ($this->sendBatch(\array_slice($batch, 0, $half), $run) === 'aborted') {
                    return 'aborted';
                }

                return $this->sendBatch(\array_slice($batch, $half), $run);
            }
            if (!self::isRetryableStatus($status)) {
                $run->failed += \count($batch);
                if (!$this->reportItemErrors($batch, $parsed, $status, $run)) {
                    $error = \is_array($parsed['error'] ?? null) ? $parsed['error'] : [];
                    $apiCode = \is_string($error['code'] ?? null) ? $error['code'] : null;
                    $apiMessage = \is_string($error['message'] ?? null) ? $error['message'] : "HTTP {$status}";
                    $this->collect($run, new ClickClacksError(
                        ClickClacksError::REQUEST_REJECTED,
                        'The API refused ' . \count($batch) . " items: {$apiMessage}",
                        count: \count($batch),
                        status: $status,
                        apiCode: $apiCode,
                        requestId: self::requestIdOf($parsed),
                    ));
                }

                return 'done';
            }
            $lastStatus = $status;
            $lastBody = $parsed;
            $lastError = null;
            if ($attempt < $this->maxRetries) {
                $retryAfter = self::parseRetryAfter($response->header('retry-after'), ($this->clock)());
                if (!$this->wait($retryAfter ?? $this->backoffDelay($attempt), $run)) {
                    return 'aborted';
                }
            }
        }

        $run->failed += \count($batch);
        foreach ($batch as $item) {
            $run->retryable[] = $item['json'];
        }
        if (!$run->reportFailures) {
            return 'done';
        }
        $error = \is_array($lastBody['error'] ?? null) ? $lastBody['error'] : [];
        $apiCode = \is_string($error['code'] ?? null) ? $error['code'] : null;
        $reason = $lastStatus !== null
            ? "HTTP {$lastStatus}" . ($apiCode !== null ? " {$apiCode}" : '')
            : ($lastError !== null ? $lastError->getMessage() : 'network error');
        $this->collect($run, new ClickClacksError(
            ClickClacksError::REQUEST_FAILED,
            'Gave up on ' . \count($batch) . ' items after ' . ($this->maxRetries + 1) . " attempts ({$reason})",
            count: \count($batch),
            status: $lastStatus,
            apiCode: $apiCode,
            requestId: self::requestIdOf($lastBody),
            previous: $lastError,
        ));

        return 'done';
    }

    /** Sleeps `ms`, or until the deadline. Returns false when the deadline cut it short. */
    private function wait(int $ms, Delivery $run): bool
    {
        $remaining = $run->remaining($this->clock);
        if ($remaining !== null && $ms >= $remaining) {
            ($this->sleep)(max(0, (int) $remaining));

            return false;
        }
        ($this->sleep)($ms);

        return true;
    }

    /** Full-jitter exponential backoff for retry `attempt` (0-based). */
    private function backoffDelay(int $attempt): int
    {
        $ceiling = min(self::BACKOFF_CAP_MS, self::BACKOFF_BASE_MS * 2 ** min($attempt, 20));

        return (int) floor(($this->random)() * $ceiling);
    }

    /**
     * @param list<array{json: string, bytes: int, insertId: string, event: string}> $batch
     * @param array<mixed>|null                                                  $body
     */
    private function readSuccess(array $batch, ?array $body, int $status, Delivery $run): void
    {
        if (\is_int($body['accepted'] ?? null)) {
            $run->accepted += $body['accepted'];
        } elseif (!$this->validate) {
            // An empty or unreadable 2xx body: the API took the request.
            $errorCount = \is_array($body['errors'] ?? null) ? \count($body['errors']) : 0;
            $run->accepted += max(0, \count($batch) - $errorCount);
        }
        if (\is_bool($body['valid'] ?? null)) {
            $run->valid = $run->valid === null ? $body['valid'] : $run->valid && $body['valid'];
        }
        if (\is_array($body['items'] ?? null)) {
            foreach ($body['items'] as $item) {
                if (\is_array($item)) {
                    /** @var array<string, mixed> $item */
                    $run->validated[] = $item;
                }
            }
        }
        if (\is_array($body['dropped'] ?? null)) {
            foreach ($body['dropped'] as $drop) {
                if (\is_array($drop) && \is_int($drop['index'] ?? null)) {
                    $run->dropped[] = [
                        'index' => $drop['index'],
                        'reason' => \is_string($drop['reason'] ?? null) ? $drop['reason'] : 'unknown',
                        'insertId' => $batch[$drop['index']]['insertId'] ?? null,
                    ];
                }
            }
        }
        $this->reportItemErrors($batch, $body, $status, $run);
        $this->reportWarnings($batch, $body, $run);
    }

    /**
     * Reports per-item errors; returns false when the body carried none.
     *
     * @param list<array{json: string, bytes: int, insertId: string, event: string}> $batch
     * @param array<mixed>|null                                                  $body
     */
    private function reportItemErrors(array $batch, ?array $body, int $status, Delivery $run): bool
    {
        $nested = \is_array($body['error'] ?? null) ? ($body['error']['errors'] ?? null) : null;
        $raw = \is_array($body['errors'] ?? null) ? $body['errors'] : (\is_array($nested) ? $nested : []);
        $itemErrors = [];
        foreach ($raw as $entry) {
            if (\is_array($entry)) {
                $itemErrors[] = self::toItemError($batch, $entry);
            }
        }
        if ($itemErrors === []) {
            return false;
        }
        array_push($run->itemErrors, ...$itemErrors);
        $codes = implode(', ', array_values(array_unique(array_map(static fn(ItemError $e): string => $e->code, $itemErrors))));
        $error = \is_array($body['error'] ?? null) ? $body['error'] : [];
        $this->collect($run, new ClickClacksError(
            ClickClacksError::ITEM_ERRORS,
            'The API refused ' . \count($itemErrors) . ' of ' . \count($batch) . " items ({$codes})",
            count: \count($itemErrors),
            status: $status,
            apiCode: \is_string($error['code'] ?? null) ? $error['code'] : null,
            requestId: self::requestIdOf($body),
            itemErrors: $itemErrors,
        ));

        return true;
    }

    /**
     * @param list<array{json: string, bytes: int, insertId: string, event: string}> $batch
     * @param array<mixed>|null                                                  $body
     */
    private function reportWarnings(array $batch, ?array $body, Delivery $run): void
    {
        $warnings = [];
        foreach (\is_array($body['warnings'] ?? null) ? $body['warnings'] : [] as $entry) {
            if (\is_array($entry)) {
                $issue = self::toItemError($batch, $entry);
                $warnings[] = new ItemWarning($issue->index, $issue->code, $issue->field, $issue->message, $issue->insertId, $issue->event);
            }
        }
        if ($warnings === []) {
            return;
        }
        array_push($run->warnings, ...$warnings);
        try {
            if ($this->onWarning !== null) {
                ($this->onWarning)($warnings);
            } else {
                $codes = implode(', ', array_values(array_unique(array_map(static fn(ItemWarning $w): string => $w->code, $warnings))));
                $this->log('info', 'warnings: the API accepted ' . \count($warnings) . " items with changes ({$codes})", [
                    'warnings' => array_map(static fn(ItemWarning $w): array => $w->toArray(), $warnings),
                ]);
            }
        } catch (\Throwable) {
            // An onWarning handler must never break delivery.
        }
    }

    // ---------------------------------------------------------------------------------
    // Reporting

    private function invalid(\InvalidArgumentException $error): void
    {
        $this->report(new ClickClacksError(ClickClacksError::INVALID_CALL, $error->getMessage(), count: 1, previous: $error));
    }

    /** Reports a problem outside a flush: through onError, or thrown with `throwOnError`. */
    private function report(ClickClacksError $error): void
    {
        if ($this->throwOnError) {
            throw $error;
        }
        $this->emit($error);
    }

    /** Records a problem met during a flush and reports it. */
    private function collect(Delivery $run, ClickClacksError $error): void
    {
        $run->errors[] = $error;
        if (!$this->throwOnError) {
            $this->emit($error);
        }
    }

    private function emit(ClickClacksError $error): void
    {
        try {
            if ($this->onError !== null) {
                ($this->onError)($error);

                return;
            }
            $context = array_filter([
                'code' => $error->errorCode,
                'count' => $error->count,
                'status' => $error->status,
                'api_code' => $error->apiCode,
                'request_id' => $error->requestId,
                'item_errors' => $error->itemErrors === [] ? null : array_map(static fn(ItemError $e): array => $e->toArray(), $error->itemErrors),
            ], static fn($value) => $value !== null);
            $this->log('warning', "{$error->errorCode}: {$error->getMessage()}", $context);
        } catch (\Throwable) {
            // An onError handler must never break delivery.
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context): void
    {
        if ($this->logger instanceof \Psr\Log\LoggerInterface) {
            $this->logger->log($level, '[clickclacks] ' . $message, $context);

            return;
        }
        error_log('[clickclacks] ' . $message);
    }

    private function safely(\Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable) {
            // Never throw from a destructor or a shutdown function.
        }
    }

    // ---------------------------------------------------------------------------------
    // Input

    /**
     * @param array<string, mixed> $params
     * @param list<string>         $allowed
     */
    private static function allowKeys(array $params, string $method, array $allowed): void
    {
        $unknown = array_diff(array_keys($params), $allowed);
        if ($unknown !== []) {
            throw new \InvalidArgumentException("{$method} got an unknown key `" . implode('`, `', array_map('strval', $unknown)) . '` (keys are camelCase, like `distinctId`)');
        }
    }

    private static function optionalId(mixed $value, string $name): ?string
    {
        if ($value === null) {
            return null;
        }
        if (\is_int($value)) {
            return (string) $value;
        }
        if (\is_float($value) && is_finite($value)) {
            return (string) $value;
        }
        if (\is_string($value)) {
            return $value;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }
        throw new \InvalidArgumentException("`{$name}` must be a string");
    }

    private static function insertId(mixed $value): string
    {
        $id = self::optionalId($value, 'insertId');
        if ($id === null || $id === '') {
            return self::generateInsertId();
        }
        if (preg_match(self::INSERT_ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException('`insertId` must be 1–80 characters of [A-Za-z0-9_-]');
        }

        return $id;
    }

    /** A fresh `insert_id`: 32 random hex characters, the same shape as the Node SDK's. */
    public static function generateInsertId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function wireTimestamp(mixed $value): string|int|float
    {
        if ($value === null) {
            return self::isoTimestamp(new \DateTimeImmutable('now'));
        }
        if ($value instanceof \DateTimeInterface) {
            return self::isoTimestamp($value);
        }
        if (\is_int($value)) {
            return $value;
        }
        if (\is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('timestamp must be finite epoch milliseconds');
            }

            return $value;
        }
        if (\is_string($value) && $value !== '') {
            return $value;
        }
        throw new \InvalidArgumentException('timestamp must be a DateTimeInterface, an ISO 8601 string or epoch milliseconds');
    }

    private static function isoTimestamp(\DateTimeInterface $value): string
    {
        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function wireProperties(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \InvalidArgumentException('properties must be an associative array');
        }
        $properties = [];
        foreach ($value as $key => $property) {
            $properties[(string) $key] = $property;
        }

        return $properties;
    }

    private static function groupType(mixed $value, string $where): string
    {
        if (!\is_string($value) || preg_match(self::GROUP_TYPE_PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException("{$where} must be 1–64 characters of [a-z0-9_]");
        }

        return $value;
    }

    private static function groupId(mixed $value, string $where): string
    {
        if (\is_int($value)) {
            $id = (string) $value;
        } elseif (\is_float($value) && is_finite($value)) {
            $id = (string) $value;
        } elseif (\is_string($value)) {
            $id = trim($value);
        } else {
            throw new \InvalidArgumentException("{$where} must be a string");
        }
        $length = mb_strlen($id, 'UTF-8');
        if ($id === '' || $length > self::MAX_GROUP_ID_LENGTH) {
            throw new \InvalidArgumentException("{$where} must be 1–255 characters");
        }
        if (preg_match(self::CONTROL_CHARACTERS, $id) !== 0) {
            throw new \InvalidArgumentException("{$where} must not contain control characters");
        }

        return $id;
    }

    /**
     * Validates the `groups` option of `track` into the `$groups` wire shape.
     *
     * @return array<string, string>|null
     */
    private static function wireGroups(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \InvalidArgumentException('groups must be an associative array of group type => group id');
        }
        if (\count($value) > self::MAX_GROUPS_PER_EVENT) {
            throw new \InvalidArgumentException('groups takes at most ' . self::MAX_GROUPS_PER_EVENT . ' group types');
        }
        $groups = [];
        foreach ($value as $type => $id) {
            $groups[self::groupType((string) $type, "groups key `{$type}`")] = self::groupId($id, "groups.{$type}");
        }

        return $groups;
    }

    // ---------------------------------------------------------------------------------
    // Responses

    private static function isRetryableStatus(int $status): bool
    {
        return $status === 408 || $status === 429 || $status >= 500 || $status === 0;
    }

    /**
     * Parses `Retry-After` (delta seconds or an HTTP date) into milliseconds, capped at 5
     * minutes. Returns null when the header is missing or unreadable.
     */
    public static function parseRetryAfter(?string $value, ?float $nowMs = null): ?int
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (preg_match('/^\d+(\.\d+)?$/D', $trimmed) === 1) {
            $ms = (float) $trimmed * 1000;
        } else {
            // An HTTP date always names a weekday or month; this keeps `-5` from parsing.
            if (preg_match('/[a-z]/i', $trimmed) !== 1) {
                return null;
            }
            $date = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $trimmed, new \DateTimeZone('UTC'))
                ?: date_create_immutable($trimmed);
            if ($date === false) {
                return null;
            }
            $ms = (float) $date->format('U.u') * 1000 - ($nowMs ?? microtime(true) * 1000);
        }

        return (int) min(self::RETRY_AFTER_CAP_MS, max(0, ceil($ms)));
    }

    /**
     * @return array<mixed>|null
     */
    private static function parseJson(string $text): ?array
    {
        if ($text === '') {
            return null;
        }
        $parsed = json_decode($text, true);

        return \is_array($parsed) ? $parsed : null;
    }

    /**
     * @param list<array{json: string, bytes: int, insertId: string, event: string}> $batch
     * @param array<mixed>                                                       $raw
     */
    private static function toItemError(array $batch, array $raw): ItemError
    {
        $index = \is_int($raw['index'] ?? null) ? $raw['index'] : -1;
        $item = $batch[$index] ?? null;

        return new ItemError(
            $index,
            \is_string($raw['code'] ?? null) ? $raw['code'] : 'unknown',
            \is_string($raw['field'] ?? null) ? $raw['field'] : null,
            \is_string($raw['message'] ?? null) ? $raw['message'] : null,
            $item !== null && $item['insertId'] !== '' ? $item['insertId'] : null,
            $item['event'] ?? null,
        );
    }

    /**
     * @param array<mixed>|null $body
     */
    private static function requestIdOf(?array $body): ?string
    {
        if (\is_string($body['request_id'] ?? null)) {
            return $body['request_id'];
        }
        $error = $body['error'] ?? null;

        return \is_array($error) && \is_string($error['request_id'] ?? null) ? $error['request_id'] : null;
    }

    /**
     * @param array<mixed> $item
     */
    private static function eventOf(array $item): string
    {
        return match ($item['type'] ?? 'track') {
            'identify' => '$identify',
            'group' => '$group_identify',
            default => \is_string($item['event'] ?? null) ? $item['event'] : '',
        };
    }

    // ---------------------------------------------------------------------------------
    // Options

    /**
     * @param array<string, mixed> $options
     */
    private static function intOption(array $options, string $name, int $default, int $min, int $max = PHP_INT_MAX): int
    {
        $value = $options[$name] ?? null;
        if ($value === null) {
            return $default;
        }
        if (\is_string($value) && is_numeric($value)) {
            $value = +$value;
        }
        if ((!\is_int($value) && !\is_float($value)) || !is_finite((float) $value) || $value < $min || $value > $max) {
            $range = $max === PHP_INT_MAX ? "at least {$min}" : "from {$min} to {$max}";
            throw new \InvalidArgumentException("ClickClacks: `{$name}` must be a number {$range}");
        }

        return (int) floor($value);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function boolOption(array $options, string $name, bool $default): bool
    {
        $value = $options[$name] ?? null;
        if ($value === null) {
            return $default;
        }
        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (!\is_bool($bool)) {
            throw new \InvalidArgumentException("ClickClacks: `{$name}` must be true or false");
        }

        return $bool;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function closureOption(array $options, string $name): ?\Closure
    {
        $value = $options[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (!\is_callable($value)) {
            throw new \InvalidArgumentException("ClickClacks: `{$name}` must be callable");
        }

        return \Closure::fromCallable($value);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function makeTransport(array $options): Transport
    {
        $transport = $options['transport'] ?? null;
        if ($transport !== null) {
            if (!$transport instanceof Transport) {
                throw new \InvalidArgumentException('ClickClacks: `transport` must implement ClickClacks\Transport\Transport');
            }

            return $transport;
        }
        $httpClient = $options['httpClient'] ?? null;
        if ($httpClient !== null) {
            if (!$httpClient instanceof \Psr\Http\Client\ClientInterface) {
                throw new \InvalidArgumentException('ClickClacks: `httpClient` must be a PSR-18 ClientInterface');
            }
            $requestFactory = $options['requestFactory'] ?? null;
            $streamFactory = $options['streamFactory'] ?? null;

            return new Psr18Transport(
                $httpClient,
                $requestFactory instanceof \Psr\Http\Message\RequestFactoryInterface ? $requestFactory : null,
                $streamFactory instanceof \Psr\Http\Message\StreamFactoryInterface ? $streamFactory : null,
            );
        }
        if (!\function_exists('curl_init')) {
            throw new \InvalidArgumentException('ClickClacks: ext-curl is not loaded. Install it, or pass a PSR-18 `httpClient`');
        }

        return new CurlTransport();
    }
}
