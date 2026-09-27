<?php

declare(strict_types=1);

namespace ClickClacks\Tests\Unit;

use ClickClacks\Testing\FakeClient;
use ClickClacks\Tests\Support\MakesClients;
use PHPUnit\Framework\TestCase;

/** The README's examples, run as written. */
final class ReadmeTest extends TestCase
{
    use MakesClients;

    public function testTheCrmExampleSendsAGroupTwoIdentifiesAndTwoEvents(): void
    {
        $api = $this->api();
        $clickclacks = $this->client($api);

        $clickclacks->group([
            'groupType' => 'company',
            'groupId' => 'cmp_311',
            'properties' => [
                'name' => "Jay's Plumbing",
                'plan' => 'pro',
                'seats' => 12,
                'industry' => 'Trades',
            ],
        ]);
        $clickclacks->identify([
            'distinctId' => 'user_8412',
            'properties' => ['role' => 'owner', 'company_id' => 'cmp_311'],
        ]);
        $clickclacks->identify([
            'distinctId' => 'user_8413',
            'properties' => ['role' => 'dispatcher', 'company_id' => 'cmp_311'],
        ]);
        $clickclacks->track([
            'event' => 'Job scheduled',
            'distinctId' => 'user_8413',
            'properties' => ['job_type' => 'boiler service', 'value_cents' => 18000],
            'groups' => ['company' => 'cmp_311'],
        ]);
        $clickclacks->track([
            'event' => 'Invoice paid',
            'distinctId' => 'user_8412',
            'insertId' => 'inv_2291',
            'properties' => ['$revenue' => 180, '$currency' => 'USD'],
            'groups' => ['company' => 'cmp_311'],
        ]);
        $result = $clickclacks->flush();

        self::assertSame([], $this->errors);
        self::assertSame(5, $result->accepted);
        $items = $api->requests[0]['json']['items'];
        self::assertSame(['group', 'identify', 'identify', 'track', 'track'], array_column($items, 'type'));
        self::assertSame(['company' => 'cmp_311'], $items[4]['properties']['$groups']);
        self::assertSame('inv_2291', $items[4]['insert_id']);
    }

    public function testTheFakeClientExample(): void
    {
        $clickclacks = new FakeClient();
        $clickclacks->track(['event' => 'Invoice paid', 'distinctId' => 'user_8412', 'properties' => ['$revenue' => 180]]);
        $clickclacks->group(['groupType' => 'company', 'groupId' => 'cmp_311']);
        $clickclacks->assertTracked('Invoice paid', static fn(array $call) => $call['properties']['$revenue'] === 180);
        $clickclacks->assertTracked('Invoice paid', times: 1);
        $clickclacks->assertGrouped('company', 'cmp_311');
        $clickclacks->assertNotTracked('Refund issued');
        $this->expectException(\RuntimeException::class);
        $clickclacks->assertNothingTracked();
    }
}
