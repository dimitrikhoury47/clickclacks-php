<?php

declare(strict_types=1);

namespace ClickClacks\Tests\Unit;

use ClickClacks\Client;
use ClickClacks\Tests\Support\MakesClients;
use ClickClacks\Tests\Support\MockApi;
use ClickClacks\Transport\TransportException;
use PHPUnit\Framework\TestCase;

final class DeliveryTest extends TestCase
{
    use MakesClients;

    // -- batching -------------------------------------------------------------------

    public function testSendsAsSoonAsTheQueueReachesFlushAt(): void
    {
        $api = $this->api();
        $client = $this->client($api, ['flushAt' => 3]);
        $client->track(['event' => 'a', 'distinctId' => 'u']);
        $client->track(['event' => 'b', 'distinctId' => 'u']);
        self::assertCount(0, $api->requests);
        self::assertSame(2, $client->pending());
        $client->track(['event' => 'c', 'distinctId' => 'u']);
        self::assertSame([3], $api->sizes());
        self::assertSame(0, $client->pending());
    }

    public function testSplitsALargeFlushIntoBatchesOfFlushAtInOrder(): void
    {
        $api = $this->api();
        $client = $this->client($api, ['flushAt' => 2]);
        for ($i = 0; $i < 5; ++$i) {
            $client->identify(['distinctId' => "u{$i}"]);
        }
        $client->flush();
        self::assertSame([2, 2, 1], $api->sizes());
        $ids = array_merge(...array_map(static fn(array $r): array => array_column($r['json']['items'], 'distinct_id'), $api->requests));
        self::assertSame(['u0', 'u1', 'u2', 'u3', 'u4'], $ids);
    }

    public function testNeverSendsMoreThan500ItemsInOneRequest(): void
    {
        $api = $this->api();
        $client = $this->client($api, ['flushAt' => 500, 'maxQueueSize' => 2000]);
        for ($i = 0; $i < 1200; ++$i) {
            $client->track(['event' => 'e', 'distinctId' => 'u']);
        }
        $client->flush();
        self::assertSame([500, 500, 200], $api->sizes());
    }

    public function testKeepsEveryRequestBodyUnder1MiBUncompressed(): void
    {
        $api = $this->api();
        $client = $this->client($api, ['flushAt' => 500]);
        for ($i = 0; $i < 30; ++$i) {
            $client->track(['event' => 'big', 'distinctId' => 'u', 'properties' => ['blob' => str_repeat('x', 100_000)]]);
        }
        $client->flush();
        self::assertGreaterThan(1, \count($api->requests));
        self::assertSame(30, array_sum($api->sizes()));
        foreach ($api->requests as $request) {
            self::assertLessThanOrEqual(Client::MAX_BODY_BYTES, \strlen((string) gzdecode($request['raw'])));
        }
    }

    public function testSplitsGroupItemsLikeAnyOtherItem(): void
    {
        $api = $this->api();
        $client = $this->client($api, ['flushAt' => 2]);
        for ($i = 0; $i < 3; ++$i) {
            $client->group(['groupType' => 'company', 'groupId' => "cmp_{$i}", 'properties' => ['blob' => str_repeat('x', 400_000)]]);
        }
        $client->flush();
        self::assertSame([2, 1], $api->sizes());
    }

    // -- gzip -----------------------------------------------------------------------

    public function testGzipsBodiesOver1KiBAndThePayloadRoundTrips(): void
    {
        $api = $this->api();
        $client = $this->client($api);
        $client->track(['event' => 'big', 'distinctId' => 'u', 'properties' => ['note' => str_repeat('clack ', 400)]]);
        $client->flush();
        $request = $api->requests[0];
        self::assertSame('gzip', $request['headers']['Content-Encoding']);
        self::assertSame("\x1f\x8b", substr($request['raw'], 0, 2));
        self::assertSame(str_repeat('clack ', 400), $request['json']['items'][0]['properties']['note']);
    }

    public function testSendsSmallBodiesAsPlainJson(): void
    {
        $api = $this->api();
        $client = $this->client($api);
        $client->track(['event' => 'small', 'distinctId' => 'u']);
        $client->flush();
        self::assertArrayNotHasKey('Content-Encoding', $api->requests[0]['headers']);
        self::assertStringStartsWith('{"items":[', $api->requests[0]['raw']);
    }

    public function testGzipCanBeTurnedOff(): void
    {
        $api = $this->api();
        $client = $this->client($api, ['gzip' => false]);
        $client->track(['event' => 'big', 'distinctId' => 'u', 'properties' => ['note' => str_repeat('clack ', 400)]]);
        $client->flush();
        self::assertArrayNotHasKey('Content-Encoding', $api->requests[0]['headers']);
    }

    // -- retries --------------------------------------------------------------------

    public function testRetries408429And5xxButNoOther4xx(): void
    {
        foreach ([408 => 2, 429 => 2, 500 => 2, 502 => 2, 503 => 2, 400 => 1, 401 => 1, 403 => 1, 404 => 1, 422 => 1] as $status => $expected) {
            $this->errors = [];
            $api = $this->api([['status' => $status, 'body' => ['error' => ['code' => "code_{$status}"]]]]);
            $client = $this->client($api);
            $client->track(['event' => 'x', 'distinctId' => 'u']);
            $client->flush();
            self::assertCount($expected, $api->requests, "HTTP {$status}");
            self::assertSame($expected === 2 ? [] : ['request_rejected'], $this->errorCodes(), "HTTP {$status}");
        }
    }

    public function testRetriesNetworkErrorsAndTimeouts(): void
    {
        $api = $this->api([new TransportException('Could not resolve host'), 'timeout']);
        $client = $this->client($api);
        $client->track(['event' => 'x', 'distinctId' => 'u']);
        $result = $client->flush();
        self::assertCount(3, $api->requests);
        self::assertSame([], $this->errors);
        self::assertSame(1, $result->accepted);
        self::assertEqualsWithDelta(10.0, $api->requests[0]['timeout'], 0.0001);
    }

    public function testBacksOff500MsTimes2ToTheNWithFullJitterCappedAt30s(): void
    {
        $api = $this->api(static fn() => ['status' => 503, 'body' => []]);
        $client = $this->client($api, ['maxRetries' => 8, 'random' => static fn(): float => 0.999999]);
        $client->track(['event' => 'x', 'distinctId' => 'u']);
        $client->flush();
        self::assertSame([499, 999, 1999, 3999, 7999, 15999, 29999, 29999], $this->clock->sleeps);
    }

    public function testDrawsJitterFromTheWholeWindow(): void
    {
        $api = $this->api([['status' => 503], ['status' => 503]]);
        $client = $this->client($api, ['random' => static fn(): float => 0.5]);
        $client->track(['event' => 'x', 'distinctId' => 'u']);
        $client->flush();
        self::assertSame([250, 500], $this->clock->sleeps);
    }

    public function testWaitsExactlyRetryAfterSecondsWhichBeatsBackoff(): void
    {
        $api = $this->api([['status' => 429, 'headers' => ['Retry-After' => '10'], 'body' => ['error' => ['code' => 'rate_limited']]]]);
        $client = $this->client($api);
        $client->track(['event' => 'x', 'distinctId' => 'u']);
        $client->flush();
        self::assertSame([10_000], $this->clock->sleeps);
        self::assertSame(10_000.0, $api->requests[1]['at'] - $api->requests[0]['at']);
    }

    public function testReadsRetryAfterAsAnHttpDate(): void
    {
        $this->clock = new \ClickClacks\Tests\Support\VirtualClock();
        $this->clock->now = (float) strtotime('2026-09-25 14:00:00 UTC') * 1000;
        $api = $this->api([['status' => 503, 'headers' => ['Retry-After' => 'Fri, 25 Sep 2026 14:00:07 GMT']]]);
        $client = $this->client($api);
        $client->track(['event' => 'x', 'distinctId' => 'u']);
        $client->flush();
        self::assertSame([7000], $this->clock->sleeps);
    }

    public function testCapsRetryAfterAt5Minutes(): void
    {
        $api = $this->api([['status' => 429, 'headers' => ['Retry-After' => '3600']]]);
        $client = $this->client($api);
        $client->track(['event' => 'x', 'distinctId' => 'u']);
        $client->flush();
        self::assertSame([300_000], $this->clock->sleeps);
    }

    public function testGivesUpAfterMaxRetriesAndReportsTheLostItemsWithTheApiCode(): void
    {
        $api = $this->api(static fn() => ['status' => 503, 'headers' => ['Retry-After' => '5'], 'body' => ['error' => ['code' => 'collection_unavailable', 'request_id' => 'ray-1']]]);
        $client = $this->client($api, ['maxRetries' => 2]);
        $client->track(['event' => 'x', 'distinctId' => 'u']);
        $client->track(['event' => 'y', 'distinctId' => 'u']);
        $result = $client->flush();
        self::assertCount(3, $api->requests);
        self::assertSame(['request_failed'], $this->errorCodes());
        $error = $this->errors[0];
        self::assertSame(2, $error->count);
        self::assertSame(503, $error->status);
        self::assertSame('collection_unavailable', $error->apiCode);
        self::assertSame('ray-1', $error->requestId);
        self::assertStringContainsString('after 3 attempts (HTTP 503 collection_unavailable)', $error->getMessage());
        self::assertSame(2, $result->failed);
        self::assertCount(2, $result->retryable);
        self::assertFalse($result->ok());
    }

    public function testReportsTheNetworkErrorWhenEveryAttemptFailed(): void
    {
        $api = $this->api(static fn() => new TransportException('Connection refused'));
        $client = $this->client($api, ['maxRetries' => 1]);
        $client->track(['event' => 'x', 'distinctId' => 'u']);
        $client->flush();
        self::assertSame(['request_failed'], $this->errorCodes());
        self::assertStringContainsString('(Connection refused)', $this->errors[0]->getMessage());
        self::assertInstanceOf(TransportException::class, $this->errors[0]->getPrevious());
    }

    public function testSplitsAndResendsOn413(): void
    {
        $api = $this->api(static fn(array $request) => \count($request['json']['items']) > 2
            ? ['status' => 413, 'body' => ['error' => ['code' => 'payload_too_large']]]
            : null);
        $client = $this->client($api);
        for ($i = 0; $i < 5; ++$i) {
            $client->track(['event' => "e{$i}", 'distinctId' => 'u']);
        }
        $result = $client->flush();
        self::assertSame([5, 3, 2, 1, 2], $api->sizes());
        self::assertSame([], $this->errors);
        self::assertSame(5, $result->accepted);
    }

    // -- per-item errors ------------------------------------------------------------

    public function testReportsErrorsInA202WithTheItemTheyBelongToAndNeverRetries(): void
    {
        $api = $this->api([MockApi::accepted(1, [
            'dropped' => [['index' => 2, 'reason' => 'bot_filtered']],
            'errors' => [['index' => 1, 'code' => 'timestamp_too_old', 'field' => 'timestamp', 'message' => 'Too old.']],
            'request_id' => 'ray-2',
        ])]);
        $client = $this->client($api);
        $client->track(['event' => 'a', 'distinctId' => 'u', 'insertId' => 'ins_0']);
        $client->track(['event' => 'b', 'distinctId' => 'u', 'insertId' => 'ins_1']);
        $client->identify(['distinctId' => 'u', 'insertId' => 'ins_2']);
        $result = $client->flush();
        self::assertCount(1, $api->requests);
        self::assertSame(['item_errors'], $this->errorCodes());
        $error = $this->errors[0];
        self::assertSame(202, $error->status);
        self::assertSame('ray-2', $error->requestId);
        self::assertSame(
            ['index' => 1, 'code' => 'timestamp_too_old', 'field' => 'timestamp', 'message' => 'Too old.', 'insert_id' => 'ins_1', 'event' => 'b'],
            $error->itemErrors[0]->toArray(),
        );
        self::assertSame([['index' => 2, 'reason' => 'bot_filtered', 'insertId' => 'ins_2']], $result->dropped);
        self::assertSame(1, $result->accepted);
    }

    public function testReportsEveryItemOn400AllItemsInvalid(): void
    {
        $api = $this->api([['status' => 400, 'body' => [
            'error' => ['code' => 'all_items_invalid', 'message' => 'Every item failed.'],
            'errors' => [['index' => 0, 'code' => 'reserved_event_name'], ['index' => 1, 'code' => 'reserved_event_name']],
        ]]]);
        $client = $this->client($api);
        $client->track(['event' => '$a', 'distinctId' => 'u']);
        $client->track(['event' => '$b', 'distinctId' => 'u']);
        $result = $client->flush();
        self::assertSame(['item_errors'], $this->errorCodes());
        self::assertSame('all_items_invalid', $this->errors[0]->apiCode);
        self::assertCount(2, $this->errors[0]->itemErrors);
        self::assertSame(2, $result->failed);
        self::assertSame([], $result->retryable);
    }

    public function testStaysQuietWhenA202HasNoErrorsEvenWithDroppedItems(): void
    {
        $api = $this->api([MockApi::accepted(0, ['dropped' => [['index' => 0, 'reason' => 'collection_paused']]])]);
        $client = $this->client($api);
        $client->track(['event' => 'a', 'distinctId' => 'u']);
        $result = $client->flush();
        self::assertSame([], $this->errors);
        self::assertTrue($result->ok());
        self::assertSame('collection_paused', $result->dropped[0]['reason']);
    }

    // -- queue cap ------------------------------------------------------------------

    public function testDropsTheNewestItemsWhenFullAndReportsARunningCount(): void
    {
        $api = $this->api();
        $client = $this->client($api, ['maxQueueSize' => 2]);
        for ($i = 0; $i < 4; ++$i) {
            $client->track(['event' => "e{$i}", 'distinctId' => 'u']);
        }
        self::assertSame(['queue_full', 'queue_full'], $this->errorCodes());
        self::assertSame([1, 2], array_map(static fn($e) => $e->dropped, $this->errors));
        self::assertSame(2, $client->droppedCount());
        $client->flush();
        self::assertSame(['e0', 'e1'], array_column($api->requests[0]['json']['items'], 'event'));
    }

    // -- deadlines ------------------------------------------------------------------

    public function testShutdownGivesUpAtItsDeadlineAndReportsWhatWasNotDelivered(): void
    {
        $api = $this->api(static fn() => ['status' => 503, 'headers' => ['Retry-After' => '5']]);
        $client = $this->client($api, ['flushAt' => 2]);
        $client->identify(['distinctId' => 'a']);
        $client->identify(['distinctId' => 'b']); // sent at once: flushAt
        $client->identify(['distinctId' => 'c']);
        $this->errors = [];
        $sentBefore = \count($api->requests);
        $result = $client->shutdown(1_000);
        self::assertSame(['shutdown_timeout'], $this->errorCodes());
        self::assertSame(1, $this->errors[0]->count);
        self::assertSame(1, \count($api->requests) - $sentBefore);
        self::assertSame([1_000], \array_slice($this->clock->sleeps, -1));
        self::assertSame(1, $result->failed);
        self::assertTrue($client->isClosed());
    }

    public function testFlushWithATimeoutReportsFlushTimeoutForEveryItemLeft(): void
    {
        $api = $this->api(static fn() => ['status' => 429, 'headers' => ['Retry-After' => '60']]);
        $client = $this->client($api, ['flushAt' => 500]);
        for ($i = 0; $i < 5; ++$i) {
            $client->track(['event' => "e{$i}", 'distinctId' => 'u']);
        }
        $result = $client->flush(2_500);
        self::assertSame(['flush_timeout'], $this->errorCodes());
        self::assertSame(5, $this->errors[0]->count);
        self::assertCount(5, $result->retryable);
        self::assertFalse($client->isClosed());
    }

    public function testShortensTheRequestTimeoutToTheTimeLeft(): void
    {
        $api = $this->api(['timeout']);
        $client = $this->client($api, ['requestTimeout' => 10_000]);
        $client->track(['event' => 'x', 'distinctId' => 'u']);
        $client->flush(3_000);
        self::assertEqualsWithDelta(3.0, $api->requests[0]['timeout'], 0.0001);
        self::assertSame(['flush_timeout'], $this->errorCodes());
    }

    public function testAFlushWithoutATimeoutWaitsForEveryRetry(): void
    {
        $api = $this->api([['status' => 429, 'headers' => ['Retry-After' => '300']], ['status' => 429, 'headers' => ['Retry-After' => '300']]]);
        $client = $this->client($api);
        $client->track(['event' => 'x', 'distinctId' => 'u']);
        $result = $client->flush();
        self::assertSame([300_000, 300_000], $this->clock->sleeps);
        self::assertSame(1, $result->accepted);
    }

    // -- prepared items and dispatch ------------------------------------------------

    public function testDispatchHandsBatchesToTheCallbackInsteadOfSending(): void
    {
        $api = $this->api();
        $handed = [];
        $client = $this->client($api, ['flushAt' => 2, 'dispatch' => static function (array $items) use (&$handed): void {
            $handed[] = $items;
        }]);
        $client->track(['event' => 'a', 'distinctId' => 'u', 'insertId' => 'ins_a']);
        $client->track(['event' => 'b', 'distinctId' => 'u', 'insertId' => 'ins_b']);
        $client->track(['event' => 'c', 'distinctId' => 'u', 'insertId' => 'ins_c']);
        $result = $client->flush();
        self::assertCount(0, $api->requests);
        self::assertCount(2, $handed);
        self::assertSame(1, $result->sent);
        self::assertSame('ins_a', json_decode($handed[0][0], true)['insert_id']);

        // The worker side: the same bytes, the same insert_ids.
        $worker = $this->client($api);
        $sent = $worker->sendPrepared($handed[0]);
        self::assertSame(2, $sent->accepted);
        self::assertSame('{"items":[' . implode(',', $handed[0]) . ']}', $api->requests[0]['raw']);
    }

    public function testADispatchFailureIsReportedAndTheItemsAreKeptForRetry(): void
    {
        $client = $this->client($this->api(), ['dispatch' => static function (): void {
            throw new \RuntimeException('Redis is down');
        }]);
        $client->track(['event' => 'a', 'distinctId' => 'u']);
        $result = $client->flush();
        self::assertSame(['request_failed'], $this->errorCodes());
        self::assertStringContainsString('Redis is down', $this->errors[0]->getMessage());
        self::assertCount(1, $result->retryable);
    }

    public function testSendPreparedCanLeaveRetryableFailuresToTheCaller(): void
    {
        $api = $this->api(static fn() => ['status' => 503]);
        $client = $this->client($api, ['maxRetries' => 1]);
        $items = ['{"type":"track","event":"a","distinct_id":"u","insert_id":"ins_a"}', 'not json', '["a list"]'];
        $result = $client->sendPrepared($items, reportFailures: false);
        self::assertSame([], $this->errors);
        self::assertSame([$items[0]], $result->retryable);
        self::assertSame([1, 1], $api->sizes());
    }

    public function testSendPreparedMatchesItemErrorsToPreparedItems(): void
    {
        $api = $this->api([MockApi::accepted(0, ['errors' => [['index' => 0, 'code' => 'invalid_properties']]])]);
        $client = $this->client($api);
        $result = $client->sendPrepared(['{"type":"group","group_type":"company","group_id":"c","insert_id":"ins_g"}']);
        self::assertSame('ins_g', $result->itemErrors[0]->insertId);
        self::assertSame('$group_identify', $result->itemErrors[0]->event);
    }
}
