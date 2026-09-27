<?php

declare(strict_types=1);

namespace ClickClacks\Tests\Support;

use ClickClacks\ClickClacksError;
use ClickClacks\Client;

trait MakesClients
{
    public const KEY = 'cks_live_testkey000000000000000000000000000000000';

    /** @var list<ClickClacksError> */
    protected array $errors = [];
    protected VirtualClock $clock;

    /**
     * @param array<string, mixed> $options
     */
    protected function client(MockApi $api, array $options = []): Client
    {
        $this->clock ??= new VirtualClock();

        return new Client([
            'key' => self::KEY,
            'transport' => $api,
            'autoFlush' => false,
            'onError' => function (ClickClacksError $error): void {
                $this->errors[] = $error;
            },
            'clock' => $this->clock->now(...),
            'sleep' => $this->clock->sleep(...),
            'random' => static fn(): float => 0.0,
            ...$options,
        ]);
    }

    protected function api(array|\Closure $replies = []): MockApi
    {
        $this->clock ??= new VirtualClock();

        return new MockApi($replies, $this->clock);
    }

    /** @return list<string> */
    protected function errorCodes(): array
    {
        return array_map(static fn(ClickClacksError $e): string => $e->errorCode, $this->errors);
    }
}
