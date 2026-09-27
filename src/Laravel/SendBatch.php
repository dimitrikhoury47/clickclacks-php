<?php

declare(strict_types=1);

namespace ClickClacks\Laravel;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends one batch of prepared items from a queue worker. Items keep the `insert_id` they got
 * when they were tracked, so sending them again is always safe.
 *
 * Retryable failures (network errors, 429, 5xx) re-dispatch only the items that failed,
 * with a growing delay, up to `clickclacks.queue_tries` times. Items the API accepted are
 * never sent twice.
 */
class SendBatch implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /** The job re-dispatches its own failures, so the queue never retries it. */
    public int $tries = 1;

    /**
     * @param list<string> $items the JSON of each wire item
     */
    public function __construct(
        public array $items,
        public int $generation = 0,
    ) {}

    public function handle(ClickClacksManager $manager): void
    {
        $client = $manager->httpClient();
        if ($client === null) {
            return;
        }
        $last = $this->generation + 1 >= $manager->queueTries();
        $result = $client->sendPrepared($this->items, $manager->flushTimeout(), reportFailures: $last);
        if (!$last && $result->retryable !== []) {
            $manager->dispatchBatch($result->retryable, $this->generation + 1, 30 * 2 ** $this->generation);
        }
    }
}
