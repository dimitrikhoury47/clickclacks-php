<?php

declare(strict_types=1);

namespace ClickClacks\Tests\Support;

use ClickClacks\ClickClacksError;
use ClickClacks\Client;

trait MakesClients
{
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
            'key' => MockApi::KEY,
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
