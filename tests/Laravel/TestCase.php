<?php

declare(strict_types=1);

namespace ClickClacks\Tests\Laravel;

use ClickClacks\Laravel\ClickClacksServiceProvider;
use ClickClacks\Laravel\Facades\ClickClacks;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ClickClacksServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['ClickClacks' => ClickClacks::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('clickclacks.key', 'cks_live_testkey000000000000000000000000000000000');
        $app['config']->set('clickclacks.host', 'https://app.clickclacks.io');
    }
}
