<?php

declare(strict_types=1);

namespace ClickClacks\Tests\Unit;

use ClickClacks\Client;
use PHPUnit\Framework\TestCase;

final class UtilTest extends TestCase
{
    public function testParseRetryAfterReadsDeltaSeconds(): void
    {
        self::assertSame(10_000, Client::parseRetryAfter('10'));
        self::assertSame(1_500, Client::parseRetryAfter(' 1.5 '));
        self::assertSame(0, Client::parseRetryAfter('0'));
    }

    public function testParseRetryAfterReadsAnHttpDate(): void
    {
        $now = (float) strtotime('2026-09-25 14:00:00 UTC') * 1000;
        self::assertSame(30_000, Client::parseRetryAfter('Fri, 25 Sep 2026 14:00:30 GMT', $now));
        self::assertSame(0, Client::parseRetryAfter('Fri, 25 Sep 2026 13:00:00 GMT', $now));
    }

    public function testParseRetryAfterCapsAt5MinutesAndIgnoresJunk(): void
    {
        self::assertSame(300_000, Client::parseRetryAfter('86400'));
        self::assertNull(Client::parseRetryAfter(null));
        self::assertNull(Client::parseRetryAfter(''));
        self::assertNull(Client::parseRetryAfter('-5'));
        self::assertNull(Client::parseRetryAfter('soon'));
    }

    public function testGenerateInsertIdIs32HexCharactersAndUnique(): void
    {
        $ids = [];
        for ($i = 0; $i < 1000; ++$i) {
            $id = Client::generateInsertId();
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
            $ids[$id] = true;
        }
        self::assertCount(1000, $ids);
    }

    public function testVersionMatchesTheNewestChangelogEntry(): void
    {
        $changelog = (string) file_get_contents(__DIR__ . '/../../CHANGELOG.md');
        self::assertMatchesRegularExpression('/^## (\S+)/m', $changelog);
        preg_match('/^## (\S+)/m', $changelog, $match);
        self::assertSame(Client::VERSION, $match[1]);
    }
}
