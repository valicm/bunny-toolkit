<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Tests\Unit\Purge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Valicm\BunnyToolkit\Api\Core\CoreApi;
use Valicm\BunnyToolkit\CacheTag\HashedTagList;
use Valicm\BunnyToolkit\CacheTag\SingleTag;
use Valicm\BunnyToolkit\CacheTag\TagFormat;
use Valicm\BunnyToolkit\Http\ApiException;
use Valicm\BunnyToolkit\Purge\PurgeLimit;
use Valicm\BunnyToolkit\Purge\Purger;
use Valicm\BunnyToolkit\Purge\PurgeResult;
use Valicm\BunnyToolkit\Purge\PurgeType;
use Valicm\BunnyToolkit\Purge\RateLimiter\InMemoryTokenBucket;
use Valicm\BunnyToolkit\Tests\Support\FakeClock;
use Valicm\BunnyToolkit\Tests\Support\FakeHttpClient;
use Valicm\BunnyToolkit\Tests\Support\TestApi;

#[CoversClass(Purger::class)]
#[CoversClass(PurgeResult::class)]
#[CoversClass(PurgeType::class)]
final class PurgerTest extends TestCase
{
    private FakeHttpClient $http;

    private FakeClock $clock;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->clock = new FakeClock();
    }

    public function testPurgesEachUniqueTagWithItsWildcardPattern(): void
    {
        $format = new HashedTagList();
        $this->http->respond(204)->respond(204);

        $result = $this->purger($format)->purgeTags(42, ['node:1', 'node:2', 'node:1']);

        self::assertTrue($result->isComplete());
        self::assertSame(['node:1', 'node:2'], $result->purged);
        self::assertCount(2, $this->http->requests);
        $request = $this->http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.bunny.net/pullzone/42/purgeCache', (string) $request->getUri());
        self::assertSame(['CacheTag' => $format->purgeValue('node:1')], FakeHttpClient::jsonBody($request));
    }

    public function testSingleTagsArePurgedByExactValue(): void
    {
        $this->http->respond(204);

        $this->purger(new SingleTag())->purgeTags(42, ['auction_lot:5:42']);

        self::assertSame(['CacheTag' => 'auction_lot:5:42'], FakeHttpClient::jsonBody($this->http->requests[0]));
    }

    public function testPurgesUrls(): void
    {
        $this->http->respond(200);

        $result = $this->purger()->purgeUrls(['https://wine.example.com/lots/*'], async: true);

        self::assertSame(['https://wine.example.com/lots/*'], $result->purged);
        $request = $this->http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/purge', $request->getUri()->getPath());
        self::assertSame(['url' => 'https://wine.example.com/lots/*', 'async' => 'true'], FakeHttpClient::query($request));
    }

    public function testPurgesWholePullZone(): void
    {
        $this->http->respond(204);

        $result = $this->purger()->purgePullZone(42);

        self::assertSame(['42'], $result->purged);
        self::assertSame('https://api.bunny.net/pullzone/42/purgeCache', (string) $this->http->requests[0]->getUri());
        self::assertSame('', (string) $this->http->requests[0]->getBody(), 'Without a tag, Bunny purges the whole zone.');
    }

    public function testDefersWhatTheRateLimitWontAllowWithoutWaiting(): void
    {
        $this->http->respond(204)->respond(204);
        $limiter = new InMemoryTokenBucket($this->clock, exact: new PurgeLimit(burst: 2, refillPerSecond: 1.0));

        $result = $this->purger(new SingleTag(), $limiter)->purgeTags(42, ['a', 'b', 'c', 'd']);

        self::assertFalse($result->isComplete());
        self::assertSame(['a', 'b'], $result->purged);
        self::assertSame(['c', 'd'], $result->deferred);
        self::assertEqualsWithDelta(1.0, $result->retryAfter, 1e-9);
        self::assertSame([], $this->clock->sleeps);
    }

    public function testWaitsForTokensWithinMaxWait(): void
    {
        $this->http->respond(204)->respond(204)->respond(204);
        $limiter = new InMemoryTokenBucket($this->clock, exact: new PurgeLimit(burst: 2, refillPerSecond: 1.0));

        $result = $this->purger(new SingleTag(), $limiter, maxWait: 5.0)->purgeTags(42, ['a', 'b', 'c']);

        self::assertTrue($result->isComplete());
        self::assertCount(1, $this->clock->sleeps);
        self::assertEqualsWithDelta(1.0, $this->clock->sleeps[0], 1e-9);
    }

    public function testRetriesAfterA429WithinMaxWait(): void
    {
        $this->http->respond(429, '', ['Retry-After' => '2'])->respond(204);

        $result = $this->purger(new SingleTag(), maxWait: 5.0)->purgeTags(42, ['a']);

        self::assertSame(['a'], $result->purged);
        self::assertCount(2, $this->http->requests);
        self::assertEqualsWithDelta(2.0, array_sum($this->clock->sleeps), 1e-9);
    }

    public function testDefersAfterA429BeyondMaxWait(): void
    {
        $this->http->respond(204)->respond(429, '', ['Retry-After' => '60']);

        $result = $this->purger(new SingleTag(), maxWait: 5.0)->purgeTags(42, ['a', 'b', 'c']);

        self::assertSame(['a'], $result->purged);
        self::assertSame(['b', 'c'], $result->deferred);
        self::assertEqualsWithDelta(60.0, $result->retryAfter, 1e-9);
        self::assertCount(2, $this->http->requests);
    }

    public function testDefersOnServerErrors(): void
    {
        $this->http->respond(204)->respond(503, 'Service unavailable');

        $result = $this->purger(new SingleTag())->purgeTags(42, ['a', 'b', 'c']);

        self::assertSame(['a'], $result->purged);
        self::assertSame(['b', 'c'], $result->deferred);
        self::assertNull($result->retryAfter);
        self::assertSame('Service unavailable', $result->reason);
    }

    public function testDefersOnNetworkErrors(): void
    {
        $this->http->failWithNetworkError('Connection timed out');

        $result = $this->purger(new SingleTag())->purgeTags(42, ['a', 'b']);

        self::assertSame([], $result->purged);
        self::assertSame(['a', 'b'], $result->deferred);
        self::assertSame('Connection timed out', $result->reason);
    }

    public function testThrowsErrorsThatRetryingWontFix(): void
    {
        $this->http->respondJson(401, ['Message' => 'Unauthorized']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unauthorized');

        $this->purger(new SingleTag())->purgeTags(42, ['a']);
    }

    public function testWildcardValuesUseThePrefixBucket(): void
    {
        self::assertSame(PurgeType::Prefix, PurgeType::forValue('* a1b2c3 *'));
        self::assertSame(PurgeType::Prefix, PurgeType::forValue('https://example.com/*'));
        self::assertSame(PurgeType::Exact, PurgeType::forValue('auction_lot:5:42'));
    }

    private function purger(
        TagFormat $format = new HashedTagList(),
        ?InMemoryTokenBucket $limiter = null,
        float $maxWait = 0.0,
    ): Purger {
        return new Purger(
            new CoreApi(TestApi::client($this->http, clock: $this->clock)),
            $format,
            $limiter ?? new InMemoryTokenBucket($this->clock),
            $maxWait,
            $this->clock,
        );
    }
}
