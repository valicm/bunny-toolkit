<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Tests\Unit\Purge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Valicm\BunnyToolkit\Purge\PurgeLimit;
use Valicm\BunnyToolkit\Purge\PurgeType;
use Valicm\BunnyToolkit\Purge\RateLimiter\InMemoryTokenBucket;
use Valicm\BunnyToolkit\Tests\Support\FakeClock;

#[CoversClass(InMemoryTokenBucket::class)]
final class InMemoryTokenBucketTest extends TestCase
{
    public function testAllowsBunnysDefaultBurstThenAsksToWait(): void
    {
        $bucket = new InMemoryTokenBucket(new FakeClock());

        for ($i = 0; $i < 120; $i++) {
            self::assertSame(0.0, $bucket->tryConsume(PurgeType::Exact));
        }
        self::assertEqualsWithDelta(0.2, $bucket->tryConsume(PurgeType::Exact), 1e-9);

        for ($i = 0; $i < 20; $i++) {
            self::assertSame(0.0, $bucket->tryConsume(PurgeType::Prefix));
        }
        self::assertEqualsWithDelta(2.0, $bucket->tryConsume(PurgeType::Prefix), 1e-9);
    }

    public function testTokensRefillOverTime(): void
    {
        $clock = new FakeClock();
        $bucket = new InMemoryTokenBucket($clock, exact: new PurgeLimit(burst: 2, refillPerSecond: 1.0));

        $bucket->tryConsume(PurgeType::Exact);
        $bucket->tryConsume(PurgeType::Exact);
        self::assertEqualsWithDelta(1.0, $bucket->tryConsume(PurgeType::Exact), 1e-9);

        $clock->advance(1.5);
        self::assertSame(0.0, $bucket->tryConsume(PurgeType::Exact));
        self::assertEqualsWithDelta(0.5, $bucket->tryConsume(PurgeType::Exact), 1e-9);

        $clock->advance(60);
        self::assertSame(0.0, $bucket->tryConsume(PurgeType::Exact));
        self::assertSame(0.0, $bucket->tryConsume(PurgeType::Exact));
        self::assertGreaterThan(0.0, $bucket->tryConsume(PurgeType::Exact), 'Refill is capped at the burst size.');
    }

    public function testBackOffBlocksUntilRetryAfterThenAllowsOne(): void
    {
        $clock = new FakeClock();
        $bucket = new InMemoryTokenBucket($clock, exact: new PurgeLimit(burst: 10, refillPerSecond: 1.0));

        $bucket->backOff(PurgeType::Exact, 3.0);
        self::assertEqualsWithDelta(3.0, $bucket->tryConsume(PurgeType::Exact), 1e-9);

        $clock->advance(3.0);
        self::assertSame(0.0, $bucket->tryConsume(PurgeType::Exact));
        self::assertEqualsWithDelta(1.0, $bucket->tryConsume(PurgeType::Exact), 1e-9);
    }

    public function testBucketsAreIndependent(): void
    {
        $bucket = new InMemoryTokenBucket(new FakeClock());

        $bucket->backOff(PurgeType::Prefix, 30.0);

        self::assertSame(0.0, $bucket->tryConsume(PurgeType::Exact));
    }
}
