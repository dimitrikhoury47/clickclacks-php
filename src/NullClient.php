<?php

declare(strict_types=1);

namespace ClickClacks;

/**
 * Accepts every call and sends nothing. The Laravel integration uses it when ClickClacks is
 * turned off (`CLICKCLACKS_ENABLED=false`) or no server key is set, so a missing key never
 * breaks your app.
 */
final class NullClient implements ClickClacksInterface
{
    /** @var (\Closure(): void)|null */
    private ?\Closure $onFirstCall;

    /**
     * @param (callable(): void)|null $onFirstCall called once, on the first call, for example to log that events are off
     */
    public function __construct(?callable $onFirstCall = null)
    {
        $this->onFirstCall = $onFirstCall === null ? null : \Closure::fromCallable($onFirstCall);
    }

    public function track(array $params): void
    {
        $this->noticeOnce();
    }

    public function identify(array $params): void
    {
        $this->noticeOnce();
    }

    public function group(array $params): void
    {
        $this->noticeOnce();
    }

    public function flush(?int $timeout = null): FlushResult
    {
        return new FlushResult();
    }

    public function shutdown(?int $timeout = null): FlushResult
    {
        return new FlushResult();
    }

    public function pending(): int
    {
        return 0;
    }

    private function noticeOnce(): void
    {
        if ($this->onFirstCall !== null) {
            $callback = $this->onFirstCall;
            $this->onFirstCall = null;
            try {
                $callback();
            } catch (\Throwable) {
                // Never let a notice break the host app.
            }
        }
    }
}
