<?php

declare(strict_types=1);

namespace ClickClacks;

/**
 * A delivery problem, passed to `onError` (or thrown when `throwOnError` is on).
 *
 * `$errorCode` is one of the constants below. PHP's own `getCode()` is an integer, so the
 * string code lives in its own property.
 */
final class ClickClacksError extends \RuntimeException
{
    /** A new item was dropped because `maxQueueSize` was reached. `$dropped` is the running count. */
    public const QUEUE_FULL = 'queue_full';
    /** A `track`/`identify`/`group` call was malformed; nothing was queued. */
    public const INVALID_CALL = 'invalid_call';
    /** One item serialised to more than the 1 MiB request cap. */
    public const ITEM_TOO_LARGE = 'item_too_large';
    /** A call arrived after `shutdown()`. */
    public const CLIENT_CLOSED = 'client_closed';
    /** The API refused some items; see `$itemErrors`. Never retried. */
    public const ITEM_ERRORS = 'item_errors';
    /** The API refused the whole request with a non-retryable status; see `$apiCode`. */
    public const REQUEST_REJECTED = 'request_rejected';
    /** Every retry failed; `$count` items were lost. */
    public const REQUEST_FAILED = 'request_failed';
    /** `shutdown()` hit its deadline; `$count` items were not delivered. */
    public const SHUTDOWN_TIMEOUT = 'shutdown_timeout';
    /** `flush($timeout)` hit its deadline; `$count` items were not delivered. */
    public const FLUSH_TIMEOUT = 'flush_timeout';

    /**
     * @param list<ItemError> $itemErrors
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        /** How many items this error concerns. */
        public readonly ?int $count = null,
        /** For `queue_full`: items dropped since the client was created. */
        public readonly ?int $dropped = null,
        /** The last HTTP status, when there was one. */
        public readonly ?int $status = null,
        /** The API's error code, for example `invalid_key` or `rate_limited`. */
        public readonly ?string $apiCode = null,
        /** The API's `request_id`, for support. */
        public readonly ?string $requestId = null,
        public readonly array $itemErrors = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
