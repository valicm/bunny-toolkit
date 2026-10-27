<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Tests\Integration\Purge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Valicm\BunnyToolkit\Purge\PurgeLimit;
use Valicm\BunnyToolkit\Purge\PurgeType;
use Valicm\BunnyToolkit\Purge\RateLimiter\RedisTokenBucket;

/**
 * The Lua script, on a real Redis or Valkey.
 *
 * Runs when BUNNY_TOOLKIT_REDIS names a server, e.g. "tcp://127.0.0.1:6379".
 * Each test uses keys of its own, removed afterwards.
 */
#[CoversClass(RedisTokenBucket::class)]
final class RedisTokenBucketTest extends TestCase
{
    private string $keyPrefix;

    /**
     * @var list<\Redis|Client>
     */
    private array $clients = [];

    protected function setUp(): void
    {
        if (self::server() === null) {
            self::markTestSkipped('Set BUNNY_TOOLKIT_REDIS to a Redis or Valkey server to run these.');
        }
        $this->keyPrefix = 'bunny-toolkit-test:' . bin2hex(random_bytes(6)) . ':';
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->del($this->keyPrefix . 'Exact', $this->keyPrefix . 'Prefix');
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function clients(): iterable
    {
        yield 'Predis' => ['predis'];
        yield 'phpredis' => ['phpredis'];
    }

    #[DataProvider('clients')]
    public function testSpendsTheBurstThenAsksToWait(string $kind): void
    {
        $bucket = $this->bucket($kind, new PurgeLimit(burst: 20, refillPerSecond: 0.5));

        // All twenty: a stored time rounded up would refuse some of them.
        for ($i = 0; $i < 20; $i++) {
            self::assertSame(0.0, $bucket->tryConsume(PurgeType::Exact), sprintf('Token %d was refused.', $i + 1));
        }
        self::assertEqualsWithDelta(2.0, $bucket->tryConsume(PurgeType::Exact), 0.05);
    }

    #[DataProvider('clients')]
    public function testProcessesShareOneBucket(string $kind): void
    {
        $limit = new PurgeLimit(burst: 3, refillPerSecond: 0.1);
        $first = $this->bucket($kind, $limit);
        $second = $this->bucket($kind, $limit);

        self::assertSame(0.0, $first->tryConsume(PurgeType::Exact));
        self::assertSame(0.0, $second->tryConsume(PurgeType::Exact));
        self::assertSame(0.0, $first->tryConsume(PurgeType::Exact));
        self::assertGreaterThan(9.0, $second->tryConsume(PurgeType::Exact), 'The second process had a bucket of its own.');
    }

    #[DataProvider('clients')]
    public function testBackOffHoldsEveryProcess(string $kind): void
    {
        $limit = new PurgeLimit(burst: 120, refillPerSecond: 5.0);
        $first = $this->bucket($kind, $limit);

        $first->backOff(PurgeType::Exact, 30.0);

        self::assertEqualsWithDelta(30.0, $this->bucket($kind, $limit)->tryConsume(PurgeType::Exact), 0.5);
        // A shorter Retry-After doesn't shorten a longer one.
        $first->backOff(PurgeType::Exact, 5.0);
        self::assertGreaterThan(29.0, $first->tryConsume(PurgeType::Exact));
    }

    #[DataProvider('clients')]
    public function testBucketsAreSeparateAndExpire(string $kind): void
    {
        $bucket = $this->bucket($kind, new PurgeLimit(burst: 1, refillPerSecond: 1.0));

        self::assertSame(0.0, $bucket->tryConsume(PurgeType::Exact));
        self::assertSame(0.0, $bucket->tryConsume(PurgeType::Prefix));

        $ttl = $this->clients[0]->ttl($this->keyPrefix . 'Exact');
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(61, $ttl);
    }

    private function bucket(string $kind, PurgeLimit $limit): RedisTokenBucket
    {
        $server = (string) self::server();
        if ($kind === 'phpredis') {
            if (!extension_loaded('redis')) {
                self::markTestSkipped('ext-redis is not loaded.');
            }
            $parts = parse_url($server);
            $client = new \Redis();
            $client->connect(is_array($parts) ? ($parts['host'] ?? '127.0.0.1') : '127.0.0.1', is_array($parts) ? ($parts['port'] ?? 6379) : 6379);
        } else {
            $client = new Client($server);
        }
        $this->clients[] = $client;

        return new RedisTokenBucket($client, $this->keyPrefix, exact: $limit, prefix: $limit);
    }

    private static function server(): ?string
    {
        $server = getenv('BUNNY_TOOLKIT_REDIS');

        return is_string($server) && $server !== '' ? $server : null;
    }
}
