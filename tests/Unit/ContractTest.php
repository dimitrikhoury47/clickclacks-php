<?php

declare(strict_types=1);

namespace ClickClacks\Tests\Unit;

use ClickClacks\Client;
use ClickClacks\Tests\Support\MakesClients;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fixture parity: replays the API's request and response shapes through the client, the
 * same way the Node SDK's `test/contract.test.ts` does.
 *
 * `tests/fixtures/spec/` holds the examples transcribed from the approved spec;
 * `tests/fixtures/server/` holds the API's own contract fixtures, copied by
 * `composer fixtures:sync`. A fixture is `{ name, request?, response? }` (one pair) or a
 * catalogue `{ errors: [{ status, code, retry }] }`.
 */
final class ContractTest extends TestCase
{
    use MakesClients;

    /** @return list<array<string, mixed>> */
    private static function fixtures(): array
    {
        $fixtures = [];
        foreach (['spec', 'server'] as $dir) {
            foreach (glob(__DIR__ . "/../fixtures/{$dir}/*.json") ?: [] as $file) {
                $fixture = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                $fixture['file'] = "{$dir}/" . basename($file);
                $fixtures[] = $fixture;
            }
        }

        return $fixtures;
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function requestFixtures(): iterable
    {
        foreach (self::fixtures() as $fixture) {
            $items = $fixture['request']['body']['items'] ?? null;
            if (\is_array($items) && $items !== []) {
                yield $fixture['file'] => [$fixture];
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function responseFixtures(): iterable
    {
        foreach (self::fixtures() as $fixture) {
            if (isset($fixture['response']['status'])) {
                yield $fixture['file'] => [$fixture];
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function catalogueEntries(): iterable
    {
        foreach (self::fixtures() as $fixture) {
            foreach (\is_array($fixture['errors'] ?? null) && isset($fixture['shape']) ? $fixture['errors'] : [] as $entry) {
                yield "{$entry['status']} {$entry['code']}" => [$entry];
            }
        }
    }

    public function testHasFixturesToReplay(): void
    {
        self::assertNotEmpty(iterator_to_array(self::requestFixtures()));
        self::assertNotEmpty(iterator_to_array(self::responseFixtures()));
        self::assertNotEmpty(iterator_to_array(self::catalogueEntries()));
    }

    /**
     * Every item the SDK can express is sent exactly as the fixture shows it. Items the API
     * would refuse for their shape (no identity, no event name, a list as properties) are
     * refused by the SDK first, as `invalid_call`, and never sent.
     *
     * @param array<string, mixed> $fixture
     */
    #[DataProvider('requestFixtures')]
    public function testRequestIsWhatTheSdkSendsForTheSameCalls(array $fixture): void
    {
        $path = (string) ($fixture['request']['path'] ?? '/api/v1/batch');
        $url = parse_url($path);
        parse_str($url['query'] ?? '', $query);
        $query = [...$query, ...($fixture['request']['query'] ?? [])];
        $headers = array_change_key_case($fixture['request']['headers'] ?? [], CASE_LOWER);
        if (($url['path'] ?? '') !== Client::BATCH_PATH
            || array_diff(array_keys($query), ['validate', 'strict']) !== []
            || ($headers['content-type'] ?? 'application/json') !== 'application/json') {
            // /api/v1/import, unknown query parameters and non-JSON bodies are not something the SDK sends.
            $this->addToAssertionCount(1);

            return;
        }

        $api = $this->api();
        $client = $this->client($api, [
            'validate' => ($query['validate'] ?? null) === 'true',
            'strict' => ($query['strict'] ?? null) === 'true',
            'flushAt' => 500,
        ]);
        $expected = [];
        $refused = 0;
        foreach ($fixture['request']['body']['items'] as $item) {
            if (!\is_array($item) || !\in_array($item['type'] ?? 'track', ['track', 'identify', 'group'], true)) {
                continue; // not expressible through the SDK (a bare string, a `page` item)
            }
            $before = \count($this->errors);
            $this->replay($client, $item);
            if (\count($this->errors) > $before) {
                self::assertSame('invalid_call', $this->errors[$before]->errorCode, json_encode($item) . ': ' . $this->errors[$before]->getMessage());
                ++$refused;
                continue;
            }
            $expected[] = $item;
        }
        self::assertCount($refused, $this->errors);
        $client->flush();
        if ($expected === []) {
            self::assertCount(0, $api->requests);

            return;
        }

        self::assertCount(1, $api->requests);
        $request = $api->requests[0];
        self::assertSame('https://app.clickclacks.io' . Client::BATCH_PATH . ($query === [] ? '' : '?' . http_build_query(array_intersect_key(['validate' => 'true', 'strict' => 'true'], $query))), $request['url']);
        self::assertMatchesRegularExpression('/^Bearer (cks_live_|sk_live_)/', $request['headers']['Authorization']);
        self::assertSame('application/json', $request['headers']['Content-Type']);
        self::assertMatchesRegularExpression('#^clickclacks-php/' . preg_quote(Client::VERSION, '#') . ' #', $request['headers']['User-Agent']);

        $sent = $request['json']['items'];
        self::assertCount(\count($expected), $sent);
        foreach ($sent as $i => $item) {
            $fixtureItem = $expected[$i];
            foreach ($fixtureItem as $key => $value) {
                // Every field the fixture shows is sent exactly as shown...
                self::assertSame($value, $item[$key] ?? null, "{$fixture['file']} item {$i} field {$key}");
            }
            // ...and the only extras are ones the SDK always adds.
            foreach (array_diff(array_keys($item), array_keys($fixtureItem)) as $extra) {
                self::assertContains($extra, ['type', 'timestamp', 'insert_id']);
            }
            self::assertSame($fixtureItem['type'] ?? 'track', $item['type']);
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,80}$/', (string) ($item['insert_id'] ?? ''));
        }
    }

    /**
     * @param array<string, mixed> $fixture
     */
    #[DataProvider('responseFixtures')]
    public function testResponseIsHandledByTheDocumentedRule(array $fixture): void
    {
        $response = $fixture['response'];
        $status = (int) $response['status'];
        $api = $this->api(static fn(array $request, int $n) => $n === 0
            ? ['status' => $status, 'headers' => $response['headers'] ?? [], 'body' => $response['body'] ?? []]
            : ['status' => 202, 'body' => []]);
        $client = $this->client($api, ['flushAt' => 500]);
        $itemErrors = \is_array($response['body']['errors'] ?? null) ? $response['body']['errors'] : [];
        $count = max([1, ...array_map(static fn(array $e): int => $e['index'] + 1, $itemErrors)]);
        for ($i = 0; $i < $count; ++$i) {
            $client->track(['event' => "e{$i}", 'distinctId' => 'u', 'insertId' => "ins_{$i}"]);
        }
        $client->flush();

        $retryable = $status === 408 || $status === 429 || $status >= 500;
        self::assertCount($retryable ? 2 : 1, $api->requests);
        $retryAfter = array_change_key_case($response['headers'] ?? [], CASE_LOWER)['retry-after'] ?? null;
        if ($retryable && $retryAfter !== null) {
            self::assertSame((float) $retryAfter * 1000, $api->requests[1]['at'] - $api->requests[0]['at']);
        }
        if ($itemErrors !== []) {
            self::assertSame(['item_errors'], $this->errorCodes());
            self::assertSame(
                array_map(static fn(array $e): array => [$e['index'], $e['code'], "ins_{$e['index']}"], $itemErrors),
                array_map(static fn($e): array => [$e->index, $e->code, $e->insertId], $this->errors[0]->itemErrors),
            );
        } elseif ($status < 300 || $retryable) {
            self::assertSame([], $this->errors);
        } else {
            self::assertSame(['request_rejected'], $this->errorCodes());
            self::assertSame($response['body']['error']['code'] ?? null, $this->errors[0]->apiCode);
        }
    }

    /**
     * @param array{status: int, code: string, retry: string, retry_after?: string, item_errors?: bool} $entry
     */
    #[DataProvider('catalogueEntries')]
    public function testErrorGetsTheRetryBehaviourTheCatalogueDocuments(array $entry): void
    {
        $errorBody = [
            'error' => ['code' => $entry['code'], 'message' => "{$entry['code']} message", 'docs_url' => 'https://…', 'request_id' => 'ray'],
            ...(($entry['item_errors'] ?? false) ? ['errors' => [['index' => 0, 'code' => 'missing_event_name', 'field' => 'event']]] : []),
        ];
        $failing = [
            'status' => $entry['status'],
            'body' => $errorBody,
            'headers' => isset($entry['retry_after']) ? ['Retry-After' => $entry['retry_after']] : [],
        ];
        // A 413 is answered for any batch of more than one item, so the split halves go through.
        $api = $this->api(static fn(array $request, int $n) => ($entry['retry'] === 'split' ? \count($request['json']['items']) > 1 : $n === 0)
            ? $failing
            : ['status' => 202, 'body' => []]);
        $client = $this->client($api);
        $items = $entry['retry'] === 'split' ? 2 : 1;
        for ($i = 0; $i < $items; ++$i) {
            $client->track(['event' => "e{$i}", 'distinctId' => 'u']);
        }
        $client->flush();

        if ($entry['retry'] === 'yes') {
            self::assertCount(2, $api->requests);
            self::assertSame([], $this->errors);
            if (isset($entry['retry_after'])) {
                self::assertSame((float) $entry['retry_after'] * 1000, $api->requests[1]['at'] - $api->requests[0]['at']);
            }
        } elseif ($entry['retry'] === 'split') {
            self::assertSame([2, 1, 1], $api->sizes());
            self::assertSame([], $this->errors);
        } else {
            self::assertCount(1, $api->requests);
            self::assertCount(1, $this->errors);
            $error = $this->errors[0];
            self::assertSame(($entry['item_errors'] ?? false) ? 'item_errors' : 'request_rejected', $error->errorCode);
            self::assertSame($entry['code'], $error->apiCode);
            self::assertSame($entry['status'], $error->status);
            self::assertSame('ray', $error->requestId);
        }
    }

    public function testA413ForASingleItemIsReportedNotRetried(): void
    {
        $api = $this->api([['status' => 413, 'body' => ['error' => ['code' => 'payload_too_large']]]]);
        $client = $this->client($api);
        $client->track(['event' => 'x', 'distinctId' => 'u']);
        $client->flush();
        self::assertCount(1, $api->requests);
        self::assertSame(['request_rejected'], $this->errorCodes());
        self::assertSame('payload_too_large', $this->errors[0]->apiCode);
    }

    /**
     * Turns a wire item back into the SDK call that should produce it.
     *
     * @param array<string, mixed> $item
     */
    private function replay(Client $client, array $item): void
    {
        $map = [
            'distinct_id' => 'distinctId',
            'anonymous_id' => 'anonymousId',
            'session_id' => 'sessionId',
            'insert_id' => 'insertId',
            'group_type' => 'groupType',
            'group_id' => 'groupId',
        ];
        $params = [];
        foreach ($item as $key => $value) {
            if ($key !== 'type') {
                $params[$map[$key] ?? $key] = $value;
            }
        }
        match ($item['type'] ?? 'track') {
            'identify' => $client->identify($params),
            'group' => $client->group($params),
            default => $client->track($params),
        };
    }
}
