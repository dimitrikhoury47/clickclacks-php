<?php

declare(strict_types=1);

return [
    /*
    | A secret server key (cks_live_…), from Settings › API keys › Server keys. Keep it in
    | the environment, never in the repository. With no key, calls are accepted and dropped.
    */
    'key' => env('CLICKCLACKS_SERVER_KEY'),

    /* The API host. Custom tracker domains don't serve the server API. */
    'host' => env('CLICKCLACKS_HOST', 'https://app.clickclacks.io'),

    /* Turn sending off without removing calls, for example in local development. */
    'enabled' => env('CLICKCLACKS_ENABLED', true),

    /*
    | Hand batches to a queued job instead of sending them after the response, so no
    | request or command ever waits on the network. `ClickClacks::queue()` does the same
    | for one call when this is off.
    */
    'queue' => env('CLICKCLACKS_QUEUE', false),
    'queue_connection' => env('CLICKCLACKS_QUEUE_CONNECTION'),
    'queue_name' => env('CLICKCLACKS_QUEUE_NAME'),
    /* How many times a queued batch that failed for a retryable reason is sent again. */
    'queue_tries' => 3,

    /* Items per request, 1–500. Reaching it sends at once. */
    'flush_at' => 100,
    /* Items held in memory per process before new ones are dropped. */
    'max_queue_size' => 10000,
    /* Retries of one request after the first attempt. */
    'max_retries' => 6,
    /* Milliseconds before one request is abandoned and retried. */
    'request_timeout' => 10000,
    /* Milliseconds a flush after a response, command or job keeps retrying. */
    'flush_timeout' => 10000,

    /* The log channel for delivery problems. Null uses the default channel. */
    'log_channel' => env('CLICKCLACKS_LOG_CHANNEL'),
];
