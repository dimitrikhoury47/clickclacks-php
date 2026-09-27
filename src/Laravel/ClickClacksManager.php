<?php

declare(strict_types=1);

namespace ClickClacks\Laravel;

use ClickClacks\ClickClacksInterface;
use ClickClacks\Client;
use ClickClacks\FlushResult;
use ClickClacks\NullClient;
use ClickClacks\Transport\Transport;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;

/**
 * What the `ClickClacks` facade and `ClickClacksInterface` resolve to in Laravel. It holds
 * one client that sends after the response and one that hands batches to a queued job,
 * and routes calls to the one `clickclacks.queue` picks.
 *
 * Nothing request-specific is kept between requests or jobs: the queues are flushed when
 * each request, command and job ends, which keeps it safe under Octane and queue workers.
 */
class ClickClacksManager implements ClickClacksInterface
{
    private ?ClickClacksInterface $direct = null;
    private ?ClickClacksInterface $queued = null;

    /**
     * @param array<string, mixed> $config the `clickclacks` config
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $config,
    ) {}

    public function track(array $params): void
    {
        $this->default()->track($params);
    }

    public function identify(array $params): void
    {
        $this->default()->identify($params);
    }

    public function group(array $params): void
    {
        $this->default()->group($params);
    }

    /** Flushes both clients (only the ones that were used). */
    public function flush(?int $timeout = null): FlushResult
    {
        $result = new FlushResult();
        foreach ([$this->queued, $this->direct] as $client) {
            if ($client !== null && $client->pending() > 0) {
                $result = $result->merge($client->flush($timeout ?? $this->flushTimeout()));
            }
        }

        return $result;
    }

    public function shutdown(?int $timeout = null): FlushResult
    {
        $result = new FlushResult();
        foreach ([$this->queued, $this->direct] as $client) {
            if ($client !== null) {
                $result = $result->merge($client->shutdown($timeout));
            }
        }

        return $result;
    }

    public function pending(): int
    {
        return ($this->direct?->pending() ?? 0) + ($this->queued?->pending() ?? 0);
    }

    /**
     * The client that hands batches to a queued job (`SendBatch`), so the caller never
     * waits on the network. `ClickClacks::queue()->track([...])`.
     */
    public function queue(): ClickClacksInterface
    {
        return $this->queued ??= $this->build(true);
    }

    /**
     * The client that sends over HTTP from this process: after the response, or at once in
     * a job or command when you call `flush()`. `ClickClacks::now()->track([...])`.
     */
    public function now(): ClickClacksInterface
    {
        return $this->direct ??= $this->build(false);
    }

    /** The client calls go to by default: queued when `clickclacks.queue` is on. */
    public function default(): ClickClacksInterface
    {
        return $this->queues() ? $this->queue() : $this->now();
    }

    public function queues(): bool
    {
        return filter_var($this->config['queue'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** The HTTP client, for the queued job. Null when sending is off. */
    public function httpClient(): ?Client
    {
        $client = $this->now();

        return $client instanceof Client ? $client : null;
    }

    public function flushTimeout(): int
    {
        return self::int($this->config['flush_timeout'] ?? null, 10_000);
    }

    public function queueTries(): int
    {
        return max(1, self::int($this->config['queue_tries'] ?? null, 3));
    }

    /**
     * Hands one batch of prepared items to the queue.
     *
     * @param list<string> $items
     */
    public function dispatchBatch(array $items, int $generation = 0, int $delaySeconds = 0): void
    {
        $job = new SendBatch($items, $generation);
        $connection = $this->config['queue_connection'] ?? null;
        $queue = $this->config['queue_name'] ?? null;
        if (\is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }
        if (\is_string($queue) && $queue !== '') {
            $job->onQueue($queue);
        }
        if ($delaySeconds > 0) {
            $job->delay($delaySeconds);
        }
        $this->container->make(Dispatcher::class)->dispatch($job);
    }

    private function build(bool $queued): ClickClacksInterface
    {
        $key = $this->config['key'] ?? null;
        $enabled = filter_var($this->config['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) {
            return new NullClient();
        }
        if (!\is_string($key) || trim($key) === '') {
            return new NullClient(fn() => $this->logger()?->warning(
                '[clickclacks] CLICKCLACKS_SERVER_KEY is not set; events are not sent.',
            ));
        }

        $options = [
            'key' => $key,
            'host' => $this->config['host'] ?? null,
            'flushAt' => $this->config['flush_at'] ?? null,
            'maxQueueSize' => $this->config['max_queue_size'] ?? null,
            'maxRetries' => $this->config['max_retries'] ?? null,
            'requestTimeout' => $this->config['request_timeout'] ?? null,
            'shutdownTimeout' => $this->flushTimeout(),
            'logger' => $this->logger(),
            // Bind ClickClacks\Transport\Transport to route requests through your own HTTP stack.
            'transport' => $this->container->bound(Transport::class) ? $this->container->make(Transport::class) : null,
        ];
        if ($queued) {
            $options['dispatch'] = fn(array $items) => $this->dispatchBatch(array_values(array_filter($items, 'is_string')));
        }

        return new Client(array_filter($options, static fn($value) => $value !== null && $value !== ''));
    }

    private function logger(): ?\Psr\Log\LoggerInterface
    {
        if (!$this->container->bound('log')) {
            return null;
        }
        $log = $this->container->make('log');
        $channel = $this->config['log_channel'] ?? null;
        if (\is_string($channel) && $channel !== '' && \is_object($log) && method_exists($log, 'channel')) {
            $log = $log->channel($channel);
        }

        return $log instanceof \Psr\Log\LoggerInterface ? $log : null;
    }

    private static function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
