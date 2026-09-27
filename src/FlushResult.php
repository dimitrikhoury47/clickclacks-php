<?php

declare(strict_types=1);

namespace ClickClacks;

/**
 * What one `flush()` did. PHP waits for the network, so unlike the Node SDK the outcome is
 * returned as well as passed to `onError` and `onWarning`.
 */
final class FlushResult
{
    /**
     * @param list<ItemError>                                        $itemErrors items the API refused
     * @param list<ItemWarning>                                      $warnings   items the API accepted with a change
     * @param list<array{index: int, reason: string, insertId: ?string}> $dropped valid items set aside by policy (`bot_filtered`, `collection_paused`)
     * @param list<ClickClacksError>                                 $errors     every problem reported during the flush
     * @param list<array<string, mixed>>                             $validated  with `validate`: the items exactly as they would be stored
     * @param list<string>                                           $retryable  JSON of items that failed for a reason worth retrying later (network, 429, 5xx, timeout)
     */
    public function __construct(
        /** Items sent (or, in queued mode, handed to the queue). */
        public readonly int $sent = 0,
        /** Items the API accepted (its `accepted` count). */
        public readonly int $accepted = 0,
        /** Items that were not delivered: refused requests, retries given up on, timeouts. */
        public readonly int $failed = 0,
        public readonly array $itemErrors = [],
        public readonly array $warnings = [],
        public readonly array $dropped = [],
        public readonly array $errors = [],
        public readonly array $validated = [],
        /** With `validate`: whether every item was valid. Null otherwise. */
        public readonly ?bool $valid = null,
        public readonly array $retryable = [],
    ) {}

    /** True when nothing was refused, lost or rejected. Warnings and policy drops don't count. */
    public function ok(): bool
    {
        return $this->errors === [];
    }

    public function merge(self $other): self
    {
        return new self(
            $this->sent + $other->sent,
            $this->accepted + $other->accepted,
            $this->failed + $other->failed,
            [...$this->itemErrors, ...$other->itemErrors],
            [...$this->warnings, ...$other->warnings],
            [...$this->dropped, ...$other->dropped],
            [...$this->errors, ...$other->errors],
            [...$this->validated, ...$other->validated],
            $this->valid === null ? $other->valid : ($other->valid === null ? $this->valid : $this->valid && $other->valid),
            [...$this->retryable, ...$other->retryable],
        );
    }
}
