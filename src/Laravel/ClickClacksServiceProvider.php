<?php

declare(strict_types=1);

namespace ClickClacks\Laravel;

use ClickClacks\ClickClacksInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

/**
 * Auto-discovered. Binds `ClickClacksManager` (the `ClickClacks` facade and
 * `ClickClacksInterface`), publishes `config/clickclacks.php`, and flushes after each
 * response, console command and queued job.
 */
class ClickClacksServiceProvider extends ServiceProvider
{
    /** Events that end a unit of work in long-running processes (queue workers, Octane). */
    private const FLUSH_EVENTS = [
        'Illuminate\Queue\Events\JobProcessed',
        'Illuminate\Queue\Events\JobFailed',
        'Illuminate\Queue\Events\JobExceptionOccurred',
        'Illuminate\Console\Events\CommandFinished',
        'Laravel\Octane\Events\RequestTerminated',
        'Laravel\Octane\Events\TaskTerminated',
        'Laravel\Octane\Events\TickTerminated',
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/clickclacks.php', 'clickclacks');

        $this->app->singleton(ClickClacksManager::class, function ($app): ClickClacksManager {
            /** @var array<string, mixed> $config */
            $config = (array) $app->make('config')->get('clickclacks', []);

            return new ClickClacksManager($app, $config);
        });
        $this->app->alias(ClickClacksManager::class, 'clickclacks');
        $this->app->alias(ClickClacksManager::class, ClickClacksInterface::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/clickclacks.php' => $this->app->configPath('clickclacks.php'),
            ], 'clickclacks-config');
        }

        // After the response has been sent (PHP-FPM finishes the request first).
        $this->app->terminating(fn() => $this->flushIfUsed());

        $this->app->make(Dispatcher::class)->listen(self::FLUSH_EVENTS, fn() => $this->flushIfUsed());
    }

    private function flushIfUsed(): void
    {
        // Never build a client just to flush it, and never throw into the host app.
        // Octane runs each request in a clone of the app and makes it the current container.
        $app = Container::getInstance();
        if (!$app->resolved(ClickClacksManager::class)) {
            return;
        }
        try {
            $manager = $app->make(ClickClacksManager::class);
            if ($manager instanceof ClickClacksInterface && $manager->pending() > 0) {
                $manager->flush();
            }
        } catch (\Throwable) {
            // Delivery problems already went to the log.
        }
    }
}
