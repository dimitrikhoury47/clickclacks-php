<?php

declare(strict_types=1);

namespace ClickClacks;

/**
 * The running tally of one flush.
 *
 * @internal
 */
final class Delivery
{
    public int $sent = 0;
    public int $accepted = 0;
    public int $failed = 0;
    /** @var list<ItemError> */
    public array $itemErrors = [];
    /** @var list<ItemWarning> */
    public array $warnings = [];
    /** @var list<array{index: int, reason: string, insertId: ?string}> */
    public array $dropped = [];
    /** @var list<ClickClacksError> */
    public array $errors = [];
    /** @var list<array<string, mixed>> */
    public array $validated = [];
    public ?bool $valid = null;
    /** @var list<string> */
    public array $retryable = [];

    public function __construct(
        /** Clock milliseconds when the flush gives up, or null for no deadline. */
        public readonly ?float $deadline,
        public readonly string $timeoutCode,
        public readonly bool $reportFailures,
    ) {}

    /**
     * Milliseconds left before the deadline, or null when there is none.
     *
     * @param \Closure(): float $clock
     */
    public function remaining(\Closure $clock): ?float
    {
        return $this->deadline === null ? null : $this->deadline - $clock();
    }

    public function result(): FlushResult
    {
        return new FlushResult(
            $this->sent,
            $this->accepted,
            $this->failed,
            $this->itemErrors,
            $this->warnings,
            $this->dropped,
            $this->errors,
            $this->validated,
            $this->valid,
            $this->retryable,
        );
    }
}
