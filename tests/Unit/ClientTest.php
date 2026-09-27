<?php

declare(strict_types=1);

namespace ClickClacks\Tests\Unit;

use ClickClacks\ClickClacksError;
use ClickClacks\Client;
use ClickClacks\ItemWarning;
use ClickClacks\NotYetSupportedError;
use ClickClacks\Tests\Support\MakesClients;
use ClickClacks\Tests\Support\MockApi;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    use MakesClients;

    // -- construction ---------------------------------------------------------------

    public function testRequiresAKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('`key` is required');
        new Client(['key' => '  ']);
    }

    public function testRefusesAPublicBrowserKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('public browser key');
        new Client(['key' => 'pk_live_abc']);
    }

    public function testValidatesHostAndNumericOptions(): void
    {
        foreach ([
            ['host' => 'app.clickclacks.io'],
            ['host' => 'ftp://app.clickclacks.io'],
            ['flushAt' => 0],
            ['flushAt' => 501],
            ['maxRetries' => -1],
            ['requestTimeout' => 0],
            ['maxQueueSize' => 'lots'],
            ['sync' => 'maybe'],
            ['flushInterval' => 5000],
        ] as $options) {
            try {
                new Client(['key' => MockApi::KEY, 'autoFlush' => false, ...$options]);
                self::fail('Expected ' . json_encode($options) . ' to be refused');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testHasTheDocumentedDefaults(): void
    {
        $client = new Client(['key' => MockApi::KEY, 'autoFlush' => false]);
        self::assertSame('https://app.clickclacks.io', $client->host);
        self::assertSame(100, $client->flushAt);
        self::assertSame(10_000, $client->maxQueueSize);
        self::assertSame(6, $client->maxRetries);
        self::assertSame(10_000, $client->requestTimeout);
        self::assertSame(10_000, $client->shutdownTimeout);
        self::assertFalse($client->sync);
        self::assertFalse($client->validate);
        self::assertFalse($client->strict);
    }

    public function testTrimsTrailingSlashesFromTheHost(): void
    {
        $api = $this->api();
        $client = $this->client($api, ['host' => 'http://localhost:8787///']);
        $client->track(['event' => 'x', 'distinctId' => 'u']);
        $client->flush();
        self::assertSame('http://localhost:8787/api/v1/batch', $api->requests[0]['url']);
    }

    public function testNeverPutsTheKeyInAnErrorMessage(): void
    {
        $api = $this->api([['status' => 401, 'body' => ['error' => ['code' => 'invalid_key', 'message' => 'Unknown key']]]]);
        $client = $this->client($api);
        $client->track(['event' => 'x', 'distinctId' => 'u']);
        $result = $client->flush();
        self::assertSame(['request_rejected'], $this->errorCodes());
        foreach ($result->errors as $error) {
            self::assertStringNotContainsString(MockApi::KEY, $error->getMessage());
            self::assertStringNotContainsString(MockApi::KEY, print_r($error->itemErrors, true));
        }
    }

    // -- the request ----------------------------------------------------------------

    public function testPostsToTheBatchPathWithTheKeyJsonAndTheSdkUserAgent(): void
    {
        $api = $this->api();
        $client = $this->client($api);
        $client->track(['event' => 'Invoice paid', 'distinctId' => 'user_8412']);
        $result = $client->flush();

        self::assertCount(1, $api->requests);
        $request = $api->requests[0];
        self::assertSame('https://app.clickclacks.io/api/v1/batch', $request['url']);
        self::assertSame('Bearer ' . MockApi::KEY, $request['headers']['Authorization']);
        self::assertSame('application/json', $request['headers']['Content-Type']);
        self::assertMatchesRegularExpression('#^clickclacks-php/\d+\.\d+\.\d+ php/#', $request['headers']['User-Agent']);
        self::assertArrayNotHasKey('Content-Encoding', $request['headers']);
        self::assertSame(1, $result->sent);
        self::assertSame(1, $result->accepted);
        self::assertTrue($result->ok());
    }

    public function testMapsTrackAndIdentifyToWireItems(): void
    {
        $api = $this->api();
        $client = $this->client($api);
        $client->track([
            'event' => 'Subscription started',
            'distinctId' => 'user_8412',
            'anonymousId' => 'per_k3J9sQ1xR2',
            'sessionId' => 'ses_abc',
            'timestamp' => new \DateTimeImmutable('2026-09-25T16:03:11.402+02:00'),
            'insertId' => 'sub_started_7f3a91',
            'properties' => ['plan' => 'pro', '$revenue' => 49.0, '$currency' => 'USD'],
        ]);
        $client->identify(['distinctId' => 8412, 'anonymousId' => 'per_k3J9sQ1xR2', 'properties' => ['plan' => 'pro']]);
        $client->flush();

        [$track, $identify] = $api->requests[0]['json']['items'];
        self::assertSame([
            'type' => 'track',
            'event' => 'Subscription started',
            'distinct_id' => 'user_8412',
            'anonymous_id' => 'per_k3J9sQ1xR2',
            'session_id' => 'ses_abc',
            'timestamp' => '2026-09-25T14:03:11.402Z',
            'insert_id' => 'sub_started_7f3a91',
            'properties' => ['plan' => 'pro', '$revenue' => 49.0, '$currency' => 'USD'],
        ], $track);
        self::assertStringContainsString('"$revenue":49.0', $api->requests[0]['raw']);
        self::assertSame('identify', $identify['type']);
        self::assertSame('8412', $identify['distinct_id']);
        self::assertSame('per_k3J9sQ1xR2', $identify['anonymous_id']);
        self::assertSame(['plan' => 'pro'], $identify['properties']);
        self::assertArrayNotHasKey('event', $identify);
    }

    public function testAcceptsEpochMillisecondsAndIsoStringsAsTimestamps(): void
    {
        $api = $this->api();
        $client = $this->client($api);
        $client->track(['event' => 'a', 'distinctId' => 'u', 'timestamp' => 1789646400000]);
        $client->track(['event' => 'b', 'distinctId' => 'u', 'timestamp' => '2026-09-25T14:03:11+02:00']);
        $client->flush();
        [$a, $b] = $api->requests[0]['json']['items'];
        self::assertSame(1789646400000, $a['timestamp']);
        self::assertSame('2026-09-25T14:03:11+02:00', $b['timestamp']);
    }

    public function testStampsATimestampAtEnqueueTimeAndAnInsertIdOnEveryItem(): void
    {
        $api = $this->api();
        $client = $this->client($api);
        $client->track(['event' => 'x', 'distinctId' => 'u']);
        $client->identify(['distinctId' => 'u']);
        $client->group(['groupType' => 'company', 'groupId' => 'cmp_311']);
        $client->flush();
        $items = $api->requests[0]['json']['items'];
        $ids = array_column($items, 'insert_id');
        foreach ($items as $item) {
            self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $item['timestamp']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $item['insert_id']);
        }
        self::assertCount(3, array_unique($ids));
    }

    public function testSnapshotsPropertiesWhenQueued(): void
    {
        $api = $this->api();
        $client = $this->client($api);
        $object = new \stdClass();
        $object->plan = 'pro';
        $client->track(['event' => 'x', 'distinctId' => 'u', 'properties' => $object]);
        $object->plan = 'free';
        $client->flush();
        self::assertSame(['plan' => 'pro'], $api->requests[0]['json']['items'][0]['properties']);
    }

    public function testSendsEmptyPropertiesAsAnObject(): void
    {
        $api = $this->api();
        $client = $this->client($api);
        $client->track(['event' => 'x', 'distinctId' => 'u', 'properties' => []]);
        $client->flush();
        self::assertStringContainsString('"properties":{}', $api->requests[0]['raw']);
    }

    public function testKeepsOneInsertIdAcrossRetriesOfTheSameBatch(): void
    {
        $api = $this->api([['status' => 503, 'body' => []], ['status' => 500, 'body' => []]]);
        $client = $this->client($api);
        $client->track(['event' => 'x', 'distinctId' => 'u']);
        $client->flush();
        self::assertCount(3, $api->requests);
        self::assertSame($api->requests[0]['raw'], $api->requests[1]['raw']);
        self::assertSame($api->requests[0]['raw'], $api->requests[2]['raw']);
    }

    // -- invalid calls --------------------------------------------------------------

    public function testReportsMalformedCallsToOnErrorAndQueuesNothing(): void
    {
        $client = $this->client($this->api());
        $client->track(['event' => '', 'distinctId' => 'u']);
        $client->track(['event' => 'x']);
        $client->track(['event' => 'x', 'distinctId' => 'u', 'properties' => ['a', 'b']]);
        $client->track(['event' => 'x', 'distinctId' => 'u', 'properties' => 'plan=pro']);
        $client->track(['event' => 'x', 'distinctId' => 'u', 'timestamp' => NAN]);
        $client->track(['event' => 'x', 'distinctId' => ['u']]);
        $client->track(['event' => 'x', 'distinct_id' => 'u']);
        $client->track(['event' => 'x', 'distinctId' => 'u', 'properties' => ['n' => NAN]]);
        $client->identify(['anonymousId' => 'per_x']);
        $client->track(['event' => 'x', 'distinctId' => 'u', 'insertId' => 'has spaces']);
        self::assertSame(array_fill(0, 10, 'invalid_call'), $this->errorCodes());
        self::assertStringContainsString('unknown key `distinct_id`', $this->errors[6]->getMessage());
        self::assertSame(0, $client->pending());
    }

    public function testReportsAnItemTooLargeForOneRequest(): void
    {
        $client = $this->client($this->api());
        $client->track(['event' => 'x', 'distinctId' => 'u', 'properties' => ['blob' => str_repeat('a', 1_000_000)]]);
        self::assertSame(['item_too_large'], $this->errorCodes());
        self::assertSame(0, $client->pending());
    }

    public function testThrowOnErrorThrowsInsteadOfReporting(): void
    {
        $client = $this->client($this->api(), ['throwOnError' => true]);
        try {
            $client->track(['event' => 'x']);
            self::fail('Expected a ClickClacksError');
        } catch (ClickClacksError $error) {
            self::assertSame('invalid_call', $error->errorCode);
        }
        self::assertSame([], $this->errors);
    }

    public function testThrowOnErrorThrowsTheFirstFlushErrorAfterTheFlush(): void
    {
        $api = $this->api([['status' => 401, 'body' => ['error' => ['code' => 'invalid_key']]]]);
        $client = $this->client($api, ['throwOnError' => true, 'flushAt' => 1]);
        $this->expectException(ClickClacksError::class);
        $client->track(['event' => 'x', 'distinctId' => 'u']);
    }

    public function testNeverLetsAnOnErrorHandlerBreakTheCaller(): void
    {
        $client = new Client([
            'key' => MockApi::KEY,
            'autoFlush' => false,
            'transport' => $this->api(),
            'onError' => static function (): void {
                throw new \RuntimeException('handler broke');
            },
        ]);
        $client->track(['event' => '']);
        $this->addToAssertionCount(1);
    }

    public function testLogsToAPsr3LoggerWhenNoOnErrorIsGiven(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<array{string, string, array<mixed>}> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = [(string) $level, (string) $message, $context];
            }
        };
        $client = new Client(['key' => MockApi::KEY, 'autoFlush' => false, 'transport' => $this->api(), 'logger' => $logger]);
        $client->track(['event' => 'x']);
        self::assertSame('warning', $logger->lines[0][0]);
        self::assertStringStartsWith('[clickclacks] invalid_call:', $logger->lines[0][1]);
        self::assertSame('invalid_call', $logger->lines[0][2]['code']);
    }

    // -- group ----------------------------------------------------------------------

    public function testQueuesAGroupItemWithNoPerson(): void
    {
        $api = $this->api();
        $client = $this->client($api);
        $client->group([
            'groupType' => 'company',
            'groupId' => ' cmp_311 ',
            'properties' => ['name' => "Jay's Plumbing", 'plan' => 'pro', 'seats' => 12],
            'timestamp' => 1789646400000,
            'insertId' => 'grp_1',
        ]);
        $client->group(['groupType' => 'company', 'groupId' => 311]);
        $client->flush();
        [$first, $second] = $api->requests[0]['json']['items'];
        self::assertSame([
            'type' => 'group',
            'group_type' => 'company',
            'group_id' => 'cmp_311',
            'timestamp' => 1789646400000,
            'insert_id' => 'grp_1',
            'properties' => ['name' => "Jay's Plumbing", 'plan' => 'pro', 'seats' => 12],
        ], $first);
        self::assertSame('311', $second['group_id']);
        self::assertArrayNotHasKey('distinct_id', $second);
        self::assertArrayNotHasKey('properties', $second);
    }

    public function testReportsInvalidGroupCallsAndQueuesNothing(): void
    {
        $client = $this->client($this->api());
        $client->group(['groupType' => 'Company', 'groupId' => 'cmp_311']);
        $client->group(['groupType' => str_repeat('a', 65), 'groupId' => 'cmp_311']);
        $client->group(['groupType' => 'company', 'groupId' => '  ']);
        $client->group(['groupType' => 'company', 'groupId' => str_repeat('a', 256)]);
        $client->group(['groupType' => 'company', 'groupId' => "cmp\n311"]);
        $client->group(['groupType' => 'company', 'groupId' => ['cmp_311']]);
        $client->group(['groupType' => 'company']);
        $client->group(['groupType' => 'company', 'groupId' => 'cmp_311', 'distinctId' => 'u']);
        self::assertSame(array_fill(0, 8, 'invalid_call'), $this->errorCodes());
        self::assertSame(0, $client->pending());
    }

    public function testAcceptsA255CharacterGroupId(): void
    {
        $client = $this->client($this->api());
        $client->group(['groupType' => 'company', 'groupId' => str_repeat('é', 255)]);
        self::assertSame([], $this->errors);
        self::assertSame(1, $client->pending());
    }

    public function testNotYetSupportedErrorStaysAvailableForAlias(): void
    {
        $error = new NotYetSupportedError('alias', 'alias() arrives with the import API');
        self::assertSame('alias', $error->method);
    }

    public function testGroupIsRefusedAfterShutdown(): void
    {
        $client = $this->client($this->api());
        $client->shutdown();
        $client->group(['groupType' => 'company', 'groupId' => 'cmp_311']);
        self::assertSame(['client_closed'], $this->errorCodes());
    }

    public function testSurfacesTheServerErrorWhenItDoesNotSupportGroupsYet(): void
    {
        $api = $this->api([MockApi::accepted(0, ['errors' => [['index' => 0, 'code' => 'item_type_not_yet_supported', 'field' => 'type', 'message' => 'group arrives with Groups.']]])]);
        $client = $this->client($api);
        $client->group(['groupType' => 'company', 'groupId' => 'cmp_311']);
        $result = $client->flush();
        self::assertSame(['item_errors'], $this->errorCodes());
        self::assertSame('item_type_not_yet_supported', $result->itemErrors[0]->code);
        self::assertSame('$group_identify', $result->itemErrors[0]->event);
    }

    // -- track groups option --------------------------------------------------------

    public function testWritesGroupsIntoPropertiesGroups(): void
    {
        $api = $this->api();
        $client = $this->client($api);
        $client->track(['event' => 'Seats changed', 'distinctId' => 'user_8412', 'properties' => ['seats' => 41], 'groups' => ['company' => 'cmp_311']]);
        $client->track(['event' => 'Seats changed', 'distinctId' => 'user_8412', 'groups' => ['company' => 'cmp_311']]);
        $client->flush();
        [$a, $b] = $api->requests[0]['json']['items'];
        self::assertSame(['seats' => 41, '$groups' => ['company' => 'cmp_311']], $a['properties']);
        self::assertSame(['$groups' => ['company' => 'cmp_311']], $b['properties']);
    }

    public function testKeepsPropertiesGroupsWorkingAndTheOptionWins(): void
    {
        $api = $this->api();
        $client = $this->client($api);
        $client->track(['event' => 'a', 'distinctId' => 'u', 'properties' => ['$groups' => ['company' => 'cmp_1']]]);
        $client->track(['event' => 'b', 'distinctId' => 'u', 'properties' => ['$groups' => ['company' => 'cmp_1']], 'groups' => ['team' => 'tm_9']]);
        $client->flush();
        [$a, $b] = $api->requests[0]['json']['items'];
        self::assertSame(['company' => 'cmp_1'], $a['properties']['$groups']);
        self::assertSame(['team' => 'tm_9'], $b['properties']['$groups']);
    }

    public function testConvertsNumberIdsAndTrimsIds(): void
    {
        $api = $this->api();
        $client = $this->client($api);
        $client->track(['event' => 'a', 'distinctId' => 'u', 'groups' => ['company' => 311, 'team' => ' tm_9 ']]);
        $client->flush();
        self::assertSame(['company' => '311', 'team' => 'tm_9'], $api->requests[0]['json']['items'][0]['properties']['$groups']);
    }

    public function testReportsAnInvalidGroupsOptionAndQueuesNothing(): void
    {
        $client = $this->client($this->api());
        $client->track(['event' => 'a', 'distinctId' => 'u', 'groups' => ['cmp_311']]);
        $client->track(['event' => 'a', 'distinctId' => 'u', 'groups' => ['Company' => 'cmp_311']]);
        $client->track(['event' => 'a', 'distinctId' => 'u', 'groups' => ['company' => '']]);
        $client->track(['event' => 'a', 'distinctId' => 'u', 'groups' => ['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5', 'f' => '6']]);
        self::assertSame(array_fill(0, 4, 'invalid_call'), $this->errorCodes());
        self::assertSame(0, $client->pending());
    }

    public function testAcceptsFiveGroupTypes(): void
    {
        $client = $this->client($this->api());
        $client->track(['event' => 'a', 'distinctId' => 'u', 'groups' => ['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5']]);
        self::assertSame([], $this->errors);
        self::assertSame(1, $client->pending());
    }

    // -- warnings -------------------------------------------------------------------

    public function testPassesTheApiWarningsToOnWarningWithTheItemTheyBelongTo(): void
    {
        $seen = [];
        $api = $this->api([MockApi::accepted(2, ['warnings' => [['index' => 1, 'code' => 'group_trait_dropped', 'field' => 'properties.email', 'message' => 'Looks personal.']]])]);
        $client = $this->client($api, ['onWarning' => static function (array $warnings) use (&$seen): void {
            $seen = $warnings;
        }]);
        $client->track(['event' => 'a', 'distinctId' => 'u', 'insertId' => 'ins_0']);
        $client->group(['groupType' => 'company', 'groupId' => 'cmp_311', 'insertId' => 'ins_1', 'properties' => ['email' => 'jay@example.com']]);
        $result = $client->flush();
        self::assertEquals([new ItemWarning(1, 'group_trait_dropped', 'properties.email', 'Looks personal.', 'ins_1', '$group_identify')], $seen);
        self::assertEquals($seen, $result->warnings);
        self::assertSame([], $this->errors);
        self::assertTrue($result->ok());
    }

    public function testNeverLetsAnOnWarningHandlerBreakDelivery(): void
    {
        $api = $this->api([MockApi::accepted(1, ['warnings' => [['index' => 0, 'code' => 'group_trait_dropped']]])]);
        $client = $this->client($api, ['onWarning' => static function (): void {
            throw new \RuntimeException('broken');
        }]);
        $client->group(['groupType' => 'company', 'groupId' => 'cmp_311']);
        $result = $client->flush();
        self::assertSame(1, $result->accepted);
    }

    // -- validate, strict and sync --------------------------------------------------

    public function testValidateSendsADryRunAndReturnsTheNormalisedItems(): void
    {
        $api = $this->api([['status' => 200, 'body' => [
            'valid' => false,
            'items' => [['index' => 0, 'event_name' => 'a', 'event_id' => 'evt_ins_0']],
            'dropped' => [],
            'errors' => [['index' => 1, 'code' => 'reserved_property', 'field' => 'properties.$foo']],
        ]]]);
        $client = $this->client($api, ['validate' => true]);
        $client->track(['event' => 'a', 'distinctId' => 'u', 'insertId' => 'ins_0']);
        $client->track(['event' => 'b', 'distinctId' => 'u', 'insertId' => 'ins_1', 'properties' => ['$foo' => 1]]);
        $result = $client->flush();
        self::assertSame('https://app.clickclacks.io/api/v1/batch?validate=true', $api->requests[0]['url']);
        self::assertFalse($result->valid);
        self::assertSame('evt_ins_0', $result->validated[0]['event_id']);
        self::assertSame(0, $result->accepted);
        self::assertSame(['item_errors'], $this->errorCodes());
        self::assertSame('ins_1', $result->itemErrors[0]->insertId);
    }

    public function testStrictAndValidateCombineInTheQuery(): void
    {
        $api = $this->api();
        $client = $this->client($api, ['validate' => true, 'strict' => true]);
        $client->track(['event' => 'a', 'distinctId' => 'u']);
        $client->flush();
        self::assertSame('https://app.clickclacks.io/api/v1/batch?validate=true&strict=true', $api->requests[0]['url']);
    }

    public function testSyncModeSendsEveryCallBeforeItReturns(): void
    {
        $api = $this->api();
        $client = $this->client($api, ['sync' => true]);
        $client->track(['event' => 'a', 'distinctId' => 'u']);
        self::assertCount(1, $api->requests);
        $client->identify(['distinctId' => 'u']);
        self::assertCount(2, $api->requests);
        self::assertSame(0, $client->pending());
    }

    // -- automatic flush ------------------------------------------------------------

    public function testFlushesWhenTheClientIsDestroyed(): void
    {
        $api = $this->api();
        $client = $this->client($api, ['autoFlush' => true]);
        $client->track(['event' => 'a', 'distinctId' => 'u']);
        unset($client);
        self::assertCount(1, $api->requests);
    }

    public function testFlushesEveryLiveClientAtShutdown(): void
    {
        $api = $this->api();
        $client = $this->client($api, ['autoFlush' => true]);
        $client->track(['event' => 'a', 'distinctId' => 'u']);
        Client::flushAllOnShutdown();
        self::assertCount(1, $api->requests);
        self::assertTrue($client->isClosed());
    }

    public function testShutdownFlushesThenRefusesNewCalls(): void
    {
        $api = $this->api();
        $client = $this->client($api);
        $client->track(['event' => 'a', 'distinctId' => 'u']);
        $result = $client->shutdown();
        self::assertSame(1, $result->sent);
        $client->track(['event' => 'b', 'distinctId' => 'u']);
        self::assertSame(['client_closed'], $this->errorCodes());
        $client->shutdown();
        self::assertCount(1, $api->requests);
    }
}
