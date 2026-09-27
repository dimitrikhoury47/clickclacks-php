<?php

declare(strict_types=1);

namespace ClickClacks\Laravel\Facades;

use ClickClacks\Laravel\ClickClacksManager;
use ClickClacks\Laravel\FakeManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void track(array<string, mixed> $params)
 * @method static void identify(array<string, mixed> $params)
 * @method static void group(array<string, mixed> $params)
 * @method static \ClickClacks\FlushResult flush(?int $timeout = null)
 * @method static \ClickClacks\FlushResult shutdown(?int $timeout = null)
 * @method static int pending()
 * @method static \ClickClacks\ClickClacksInterface queue()
 * @method static \ClickClacks\ClickClacksInterface now()
 * @method static void assertTracked(string $event, ?callable $filter = null, ?int $times = null)
 * @method static void assertNotTracked(string $event, ?callable $filter = null)
 * @method static void assertNothingTracked()
 * @method static void assertIdentified(string|int $distinctId, ?callable $filter = null)
 * @method static void assertGrouped(string $groupType, string|int $groupId, ?callable $filter = null)
 *
 * @see ClickClacksManager
 * @see FakeManager
 */
class ClickClacks extends Facade
{
    /**
     * Replaces the client with a fake that records calls and sends nothing.
     */
    public static function fake(): FakeManager
    {
        $fake = new FakeManager();
        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return ClickClacksManager::class;
    }
}
