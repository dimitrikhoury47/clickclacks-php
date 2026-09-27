<?php

declare(strict_types=1);

namespace ClickClacks;

/**
 * What `Client` and `Testing\FakeClient` share. Type-hint against it so tests can swap in
 * the fake.
 *
 * @phpstan-type TrackParams array{event: string, distinctId?: string|int|null, anonymousId?: string|null, sessionId?: string|null, timestamp?: \DateTimeInterface|string|int|float|null, insertId?: string|null, properties?: array<string, mixed>|object|null, groups?: array<string, string|int>|null}
 * @phpstan-type IdentifyParams array{distinctId: string|int, anonymousId?: string|null, timestamp?: \DateTimeInterface|string|int|float|null, insertId?: string|null, properties?: array<string, mixed>|object|null}
 * @phpstan-type GroupParams array{groupType: string, groupId: string|int, properties?: array<string, mixed>|object|null, timestamp?: \DateTimeInterface|string|int|float|null, insertId?: string|null}
 */
interface ClickClacksInterface
{
    /**
     * Queues an event. Never throws for bad input (unless `throwOnError` is on): problems
     * go to `onError`.
     *
     * @param array<string, mixed> $params `event`, `distinctId` or `anonymousId`, and optionally
     *                                     `sessionId`, `timestamp`, `insertId`, `properties`, `groups`
     */
    public function track(array $params): void;

    /**
     * Records traits for a user, and links `anonymousId` (if given) to them.
     *
     * @param array<string, mixed> $params `distinctId`, and optionally `anonymousId`, `timestamp`,
     *                                     `insertId`, `properties`
     */
    public function identify(array $params): void;

    /**
     * Records traits for a group, such as a company. The newest call replaces the group's
     * whole trait set.
     *
     * @param array<string, mixed> $params `groupType`, `groupId`, and optionally `properties`,
     *                                     `timestamp`, `insertId`
     */
    public function group(array $params): void;

    /**
     * Sends everything queued now and returns when it's delivered, refused or given up on.
     * Never throws (unless `throwOnError` is on).
     *
     * @param int|null $timeout milliseconds before giving up; null waits for every retry
     */
    public function flush(?int $timeout = null): FlushResult;

    /**
     * Flushes with a deadline (default `shutdownTimeout`) and then refuses new calls.
     * Safe to call more than once.
     */
    public function shutdown(?int $timeout = null): FlushResult;

    /** Items queued and not yet sent. */
    public function pending(): int;
}
