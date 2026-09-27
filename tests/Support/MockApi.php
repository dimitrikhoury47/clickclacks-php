<?php

declare(strict_types=1);

namespace ClickClacks\Tests\Support;

use ClickClacks\Transport\HttpRequest;
use ClickClacks\Transport\HttpResponse;
use ClickClacks\Transport\Transport;
use ClickClacks\Transport\TransportException;

/**
 * A scripted fake of the API. Replies are used in order; after they run out, every request
 * gets a 202 that accepts all items. A reply is `['status' => …, 'body' => …, 'headers' => …]`,
 * a `\Throwable` (thrown as a network error) or `'timeout'` (which also advances the clock
 * by the request timeout).
 */
final class MockApi implements Transport
{
    public const KEY = 'cks_live_testkey000000000000000000000000000000000';

    /** @var list<array{url: string, headers: array<string, string>, raw: string, json: array{items: list<array<string, mixed>>}, at: float, timeout: float}> */
    public array $requests = [];

    /** @var list<mixed>|\Closure */
    private array|\Closure $replies;

    /**
     * @param list<mixed>|\Closure $replies
     */
    public function __construct(array|\Closure $replies = [], private readonly ?VirtualClock $clock = null)
    {
        $this->replies = $replies;
    }

    public static function accepted(int $count, array $extra = []): array
    {
        return ['status' => 202, 'body' => ['accepted' => $count, 'dropped' => [], 'errors' => [], 'request_id' => 'req-test', ...$extra]];
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $text = ($request->headers['Content-Encoding'] ?? null) === 'gzip' ? gzdecode($request->body) : $request->body;
        $json = json_decode((string) $text, true, 512, JSON_THROW_ON_ERROR);
        $captured = [
            'url' => $request->url,
            'headers' => $request->headers,
            'raw' => $request->body,
            'json' => $json,
            'at' => $this->clock?->now ?? 0.0,
            'timeout' => $request->timeout,
        ];
        $this->requests[] = $captured;
        $n = \count($this->requests) - 1;
        $reply = $this->replies instanceof \Closure
            ? ($this->replies)($captured, $n)
            : ($this->replies[$n] ?? self::accepted(\count($json['items'])));
        $reply ??= self::accepted(\count($json['items']));

        if ($reply === 'timeout') {
            if ($this->clock !== null) {
                $this->clock->now += $request->timeout * 1000;
            }
            throw new TransportException('Operation timed out');
        }
        if ($reply instanceof \Throwable) {
            throw $reply instanceof TransportException ? $reply : new TransportException($reply->getMessage(), 0, $reply);
        }

        return new HttpResponse(
            $reply['status'],
            ['content-type' => 'application/json', ...($reply['headers'] ?? [])],
            json_encode($reply['body'] ?? new \stdClass(), JSON_THROW_ON_ERROR),
        );
    }

    /** @return list<int> item counts per request */
    public function sizes(): array
    {
        return array_map(static fn(array $r): int => \count($r['json']['items']), $this->requests);
    }
}
