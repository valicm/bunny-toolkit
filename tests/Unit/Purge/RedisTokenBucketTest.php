<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Tests\Unit\Purge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Psr\Log\AbstractLogger;
use Valicm\BunnyToolkit\Purge\PurgeLimit;
use Valicm\BunnyToolkit\Purge\PurgeType;
use Valicm\BunnyToolkit\Purge\RateLimiter\InMemoryTokenBucket;
use Valicm\BunnyToolkit\Purge\RateLimiter\RedisTokenBucket;
use Valicm\BunnyToolkit\Tests\Support\FakeClock;

/**
 * What happens without a server. The script itself is tested against a real
 * one in tests/Integration.
 */
#[CoversClass(RedisTokenBucket::class)]
final class RedisTokenBucketTest extends TestCase
{
    public function testCountsInTheProcessWhenTheServerIsOut(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            /**
             * @param array<array-key, mixed> $context
             */
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->warnings[] = strtr((string) $message, ['{reason}' => is_string($context['reason'] ?? null) ? $context['reason'] : '']);
            }
        };
        $fallback = new InMemoryTokenBucket(new FakeClock(), exact: new PurgeLimit(burst: 2, refillPerSecond: 1.0));
        $bucket = new RedisTokenBucket(self::unreachable(), logger: $logger, fallback: $fallback);

        self::assertSame(0.0, $bucket->tryConsume(PurgeType::Exact));
        self::assertSame(0.0, $bucket->tryConsume(PurgeType::Exact));
        self::assertEqualsWithDelta(1.0, $bucket->tryConsume(PurgeType::Exact), 1e-9);
        $bucket->backOff(PurgeType::Exact, 30.0);
        self::assertEqualsWithDelta(30.0, $bucket->tryConsume(PurgeType::Exact), 1e-9);

        self::assertCount(1, $logger->warnings, 'The server was tried again after it failed.');
        self::assertStringContainsString('the server did not answer', $logger->warnings[0]);
    }

    public function testFallbackKeepsTheConfiguredLimits(): void
    {
        $bucket = new RedisTokenBucket(self::unreachable(), prefix: new PurgeLimit(burst: 1, refillPerSecond: 0.25));

        self::assertSame(0.0, $bucket->tryConsume(PurgeType::Prefix));
        self::assertGreaterThan(3.9, $bucket->tryConsume(PurgeType::Prefix));
    }

    /**
     * A client for a port nothing listens on.
     */
    private static function unreachable(): Client
    {
        return new Client(['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.5]);
    }
}
