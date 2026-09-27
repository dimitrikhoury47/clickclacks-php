<?php

declare(strict_types=1);

namespace ClickClacks\Tests\Support;

/** A clock that only moves when the client sleeps, so retry timing is exact and instant. */
final class VirtualClock
{
    public float $now = 1_790_000_000_000.0;
    /** @var list<int> every sleep, in milliseconds */
    public array $sleeps = [];

    public function now(): float
    {
        return $this->now;
    }

    public function sleep(int $ms): void
    {
        $this->sleeps[] = $ms;
        $this->now += $ms;
    }
}
