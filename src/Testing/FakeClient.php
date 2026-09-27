<?php

declare(strict_types=1);

namespace ClickClacks\Testing;

use ClickClacks\ClickClacksInterface;
use ClickClacks\FlushResult;

/**
 * A stand-in for `ClickClacks\Client` in your tests. It sends nothing and records every
 * call, so you can assert on what your code tracked.
 *
 * ```php
 * $clickclacks = new FakeClient();
 * $service = new Billing($clickclacks);
 * $service->pay($invoice);
 * $clickclacks->assertTracked('Invoice paid', fn (array $call) => $call['properties']['amount_cents'] === 4900);
 * ```
 *
 * Assertions throw `\RuntimeException` so the fake works with any test framework; PHPUnit
 * reports them as errors with the message.
 */
class FakeClient implements ClickClacksInterface
{
    /** @var list<array<string, mixed>> */
    public array $tracked = [];
    /** @var list<array<string, mixed>> */
    public array $identified = [];
    /** @var list<array<string, mixed>> */
    public array $grouped = [];
    public int $flushes = 0;
    private bool $closed = false;

    public function track(array $params): void
    {
        $this->tracked[] = $params;
    }

    public function identify(array $params): void
    {
        $this->identified[] = $params;
    }

    public function group(array $params): void
    {
        $this->grouped[] = $params;
    }

    public function flush(?int $timeout = null): FlushResult
    {
        ++$this->flushes;

        return new FlushResult();
    }

    public function shutdown(?int $timeout = null): FlushResult
    {
        $this->closed = true;

        return $this->flush($timeout);
    }

    public function pending(): int
    {
        return 0;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * The `track` calls for one event, optionally filtered.
     *
     * @param (callable(array<string, mixed>): bool)|null $filter
     *
     * @return list<array<string, mixed>>
     */
    public function trackedCalls(string $event, ?callable $filter = null): array
    {
        return array_values(array_filter(
            $this->tracked,
            static fn(array $call): bool => ($call['event'] ?? null) === $event && ($filter === null || $filter($call)),
        ));
    }

    /**
     * @param (callable(array<string, mixed>): bool)|null $filter
     */
    public function assertTracked(string $event, ?callable $filter = null, ?int $times = null): void
    {
        $count = \count($this->trackedCalls($event, $filter));
        if ($times === null ? $count === 0 : $count !== $times) {
            $expected = $times === null ? 'at least once' : "{$times} times";
            throw new \RuntimeException("Expected \"{$event}\" to be tracked {$expected}; it was tracked {$count} times.");
        }
    }

    /**
     * @param (callable(array<string, mixed>): bool)|null $filter
     */
    public function assertNotTracked(string $event, ?callable $filter = null): void
    {
        $count = \count($this->trackedCalls($event, $filter));
        if ($count > 0) {
            throw new \RuntimeException("Expected \"{$event}\" not to be tracked; it was tracked {$count} times.");
        }
    }

    public function assertNothingTracked(): void
    {
        if ($this->tracked !== []) {
            throw new \RuntimeException('Expected nothing to be tracked; ' . \count($this->tracked) . ' events were.');
        }
    }

    /**
     * @param (callable(array<string, mixed>): bool)|null $filter
     */
    public function assertIdentified(string|int $distinctId, ?callable $filter = null): void
    {
        foreach ($this->identified as $call) {
            if ((string) ($call['distinctId'] ?? '') === (string) $distinctId && ($filter === null || $filter($call))) {
                return;
            }
        }
        throw new \RuntimeException("Expected \"{$distinctId}\" to be identified.");
    }

    /**
     * @param (callable(array<string, mixed>): bool)|null $filter
     */
    public function assertGrouped(string $groupType, string|int $groupId, ?callable $filter = null): void
    {
        foreach ($this->grouped as $call) {
            if (($call['groupType'] ?? null) === $groupType
                && (string) ($call['groupId'] ?? '') === (string) $groupId
                && ($filter === null || $filter($call))) {
                return;
            }
        }
        throw new \RuntimeException("Expected group {$groupType}/{$groupId} to be recorded.");
    }
}
