<?php

declare(strict_types=1);

namespace ClickClacks\Tests\Laravel;

use ClickClacks\ClickClacksInterface;
use ClickClacks\Client;
use ClickClacks\Laravel\ClickClacksManager;
use ClickClacks\Laravel\Facades\ClickClacks;
use ClickClacks\Laravel\SendBatch;
use ClickClacks\NullClient;
use ClickClacks\Tests\Support\MockApi;
use ClickClacks\Transport\Transport;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class ServiceProviderTest extends TestCase
{
    private MockApi $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = new MockApi();
    }

    /** Points the manager's HTTP clients at the mock API. */
    private function useMockApi(?MockApi $api = null): ClickClacksManager
    {
        $this->api = $api ?? $this->api;
        $this->app->instance(Transport::class, $this->api);
        $this->app->forgetInstance(ClickClacksManager::class);
        ClickClacks::clearResolvedInstances();

        return $this->app->make(ClickClacksManager::class);
    }

    public function testMergesTheConfigAndBindsTheManager(): void
    {
        self::assertSame(100, config('clickclacks.flush_at'));
        self::assertFalse(config('clickclacks.queue'));
        $manager = $this->app->make(ClickClacksManager::class);
        self::assertSame($manager, $this->app->make('clickclacks'));
        self::assertSame($manager, $this->app->make(ClickClacksInterface::class));
        self::assertSame($manager, ClickClacks::getFacadeRoot());
    }

    public function testBuildsAnHttpClientFromTheConfig(): void
    {
        config(['clickclacks.flush_at' => 50, 'clickclacks.host' => 'https://eu.example.test/']);
        $this->app->forgetInstance(ClickClacksManager::class);
        $client = $this->app->make(ClickClacksManager::class)->now();
        self::assertInstanceOf(Client::class, $client);
        self::assertSame(50, $client->flushAt);
        self::assertSame('https://eu.example.test', $client->host);
    }

    public function testDropsCallsWithoutBreakingTheAppWhenNoKeyIsSet(): void
    {
        config(['clickclacks.key' => null]);
        $this->app->forgetInstance(ClickClacksManager::class);
        ClickClacks::clearResolvedInstances();
        self::assertInstanceOf(NullClient::class, ClickClacks::now());
        ClickClacks::track(['event' => 'Invoice paid', 'distinctId' => 'user_8412']);
        self::assertSame(0, ClickClacks::pending());
        self::assertNull(ClickClacks::getFacadeRoot()->httpClient());
    }

    public function testCanBeTurnedOff(): void
    {
        config(['clickclacks.enabled' => false]);
        $this->app->forgetInstance(ClickClacksManager::class);
        self::assertInstanceOf(NullClient::class, $this->app->make(ClickClacksManager::class)->now());
    }

    public function testFlushesAfterTheResponseWhenTheAppTerminates(): void
    {
        $this->useMockApi();
        ClickClacks::track(['event' => 'Invoice paid', 'distinctId' => 'user_8412']);
        self::assertCount(0, $this->api->requests);
        $this->app->terminate();
        self::assertCount(1, $this->api->requests);
        self::assertSame('Invoice paid', $this->api->requests[0]['json']['items'][0]['event']);
    }

    public function testFlushesAfterEachQueuedJobAndConsoleCommand(): void
    {
        $this->useMockApi();
        ClickClacks::identify(['distinctId' => 'user_8412']);
        event(new JobProcessed('redis', $this->createStub(Job::class)));
        self::assertCount(1, $this->api->requests);

        ClickClacks::identify(['distinctId' => 'user_9000']);
        event(new CommandFinished('report:send', new ArrayInput([]), new NullOutput(), 0));
        self::assertCount(2, $this->api->requests);
        self::assertSame(0, ClickClacks::pending());
    }

    public function testDoesNotBuildAClientJustToFlushIt(): void
    {
        $this->app->forgetInstance(ClickClacksManager::class);
        $this->app->terminate();
        self::assertFalse($this->app->resolved(ClickClacksManager::class));
    }

    public function testQueueHandsBatchesToAQueuedJob(): void
    {
        Bus::fake([SendBatch::class]);
        $this->useMockApi();
        ClickClacks::queue()->track(['event' => 'Export finished', 'distinctId' => 'user_8412', 'insertId' => 'exp_1']);
        $this->app->terminate();
        self::assertCount(0, $this->api->requests);
        Bus::assertDispatched(SendBatch::class, static function (SendBatch $job): bool {
            return \count($job->items) === 1 && json_decode($job->items[0], true)['insert_id'] === 'exp_1';
        });
    }

    public function testTheQueueConfigMakesQueuedTheDefault(): void
    {
        config(['clickclacks.queue' => true, 'clickclacks.queue_connection' => 'redis', 'clickclacks.queue_name' => 'analytics']);
        $this->app->forgetInstance(ClickClacksManager::class);
        ClickClacks::clearResolvedInstances();
        Bus::fake([SendBatch::class]);
        $this->useMockApi();
        ClickClacks::track(['event' => 'Export finished', 'distinctId' => 'user_8412']);
        ClickClacks::flush();
        self::assertCount(0, $this->api->requests);
        Bus::assertDispatched(SendBatch::class, static fn(SendBatch $job): bool => $job->connection === 'redis' && $job->queue === 'analytics');
    }

    public function testTheJobSendsThePreparedItemsWithTheirInsertIds(): void
    {
        config(['queue.default' => 'sync']);
        $this->useMockApi();
        ClickClacks::queue()->track(['event' => 'Export finished', 'distinctId' => 'user_8412', 'insertId' => 'exp_1']);
        ClickClacks::queue()->group(['groupType' => 'company', 'groupId' => 'cmp_311', 'properties' => ['name' => "Jay's Plumbing"]]);
        ClickClacks::flush();
        self::assertCount(1, $this->api->requests);
        self::assertSame(['exp_1'], \array_slice(array_column($this->api->requests[0]['json']['items'], 'insert_id'), 0, 1));
        self::assertSame('group', $this->api->requests[0]['json']['items'][1]['type']);
    }

    public function testTheJobRedispatchesOnlyTheItemsThatFailed(): void
    {
        config(['clickclacks.max_retries' => 0]);
        $manager = $this->useMockApi(new MockApi(static fn() => ['status' => 503]));
        Bus::fake([SendBatch::class]);
        $job = new SendBatch(['{"type":"identify","distinct_id":"u","insert_id":"ins_1"}']);
        $job->handle($manager);
        Bus::assertDispatched(SendBatch::class, static fn(SendBatch $next): bool => $next->generation === 1
            && $next->items === ['{"type":"identify","distinct_id":"u","insert_id":"ins_1"}']
            && $next->delay === 30);

        // The last try gives up and reports.
        $last = new SendBatch(['{"type":"identify","distinct_id":"u","insert_id":"ins_1"}'], 2);
        Bus::fake([SendBatch::class]);
        $last->handle($manager);
        Bus::assertNotDispatched(SendBatch::class);
    }

    public function testFakeRecordsCallsAndAsserts(): void
    {
        $fake = ClickClacks::fake();
        ClickClacks::track(['event' => 'Invoice paid', 'distinctId' => 'user_8412', 'properties' => ['amount_cents' => 4900]]);
        ClickClacks::queue()->identify(['distinctId' => 'user_8412']);
        ClickClacks::now()->group(['groupType' => 'company', 'groupId' => 'cmp_311']);
        ClickClacks::assertTracked('Invoice paid', static fn(array $call): bool => $call['properties']['amount_cents'] === 4900);
        ClickClacks::assertNotTracked('Refund issued');
        ClickClacks::assertIdentified('user_8412');
        ClickClacks::assertGrouped('company', 'cmp_311');
        self::assertCount(1, $fake->tracked);
        self::assertSame($fake, $this->app->make(ClickClacksInterface::class));
        $this->app->terminate();
        self::assertSame(0, $fake->flushes);
    }

    public function testTheFakeFailsLoudly(): void
    {
        ClickClacks::fake();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Expected "Invoice paid" to be tracked at least once');
        ClickClacks::assertTracked('Invoice paid');
    }

    public function testKeepsNoStateBetweenRequests(): void
    {
        $this->useMockApi();
        ClickClacks::track(['event' => 'first', 'distinctId' => 'a']);
        $this->app->terminate();
        ClickClacks::track(['event' => 'second', 'distinctId' => 'b']);
        $this->app->terminate();
        self::assertSame([['first'], ['second']], array_map(
            static fn(array $r): array => array_column($r['json']['items'], 'event'),
            $this->api->requests,
        ));
    }

    public function testPublishesTheConfig(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'clickclacks-config', '--force' => true])->assertSuccessful();
        self::assertFileExists(config_path('clickclacks.php'));
        @unlink(config_path('clickclacks.php'));
    }
}
