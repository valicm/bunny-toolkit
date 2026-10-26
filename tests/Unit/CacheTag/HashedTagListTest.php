<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Tests\Unit\CacheTag;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Valicm\BunnyToolkit\CacheTag\HashedTagList;

#[CoversClass(HashedTagList::class)]
final class HashedTagListTest extends TestCase
{
    public function testHeaderPacksHashedTokensBetweenGuards(): void
    {
        $format = new HashedTagList();

        $header = $format->header(['node:1', 'commerce_product:7']);

        self::assertMatchesRegularExpression('/^: [A-Za-z0-9_-]{5} [A-Za-z0-9_-]{5} :$/', $header->value);
        self::assertSame(['node:1', 'commerce_product:7'], $header->included);
        self::assertSame([], $header->dropped);
        self::assertStringContainsString(' ' . $format->token('node:1') . ' ', $header->value);
    }

    public function testRepeatedTagsAreWrittenOnce(): void
    {
        $header = (new HashedTagList())->header(['node:1', 'node:1', 'node:2']);

        self::assertSame(['node:1', 'node:2'], $header->included);
        self::assertCount(2, explode(' ', trim($header->value, ': ')));
    }

    public function testTagsThatDontFitAreDroppedFromTheEnd(): void
    {
        // Guards and spaces take 4 bytes, the first token 5 and each later one 6.
        $format = new HashedTagList(hashLength: 5, maxBytes: 15);

        $header = $format->header(['a', 'b', 'c', 'd']);

        self::assertSame(['a', 'b'], $header->included);
        self::assertSame(['c', 'd'], $header->dropped);
        self::assertSame(15, strlen($header->value));
    }

    public function testDefaultHeaderStaysWithinBunnysLimit(): void
    {
        $tags = array_map(static fn(int $i): string => 'node:' . $i, range(1, 200));

        $header = (new HashedTagList())->header($tags);

        self::assertLessThanOrEqual(1024, strlen($header->value));
        self::assertCount(170, $header->included);
        self::assertCount(30, $header->dropped);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function hashLengths(): iterable
    {
        yield '3 characters' => [3, 255];
        yield '4 characters' => [4, 204];
        yield '5 characters' => [5, 170];
        yield '6 characters' => [6, 145];
        yield '7 characters' => [7, 127];
        yield '8 characters' => [8, 113];
    }

    #[DataProvider('hashLengths')]
    public function testHashLengthSetsHowManyTagsFit(int $hashLength, int $fits): void
    {
        $tags = array_map(static fn(int $i): string => 'node:' . $i, range(1, 400));

        $header = (new HashedTagList(hashLength: $hashLength))->header($tags);

        self::assertLessThanOrEqual(1024, strlen($header->value));
        self::assertMatchesRegularExpression(sprintf('/^: [A-Za-z0-9_-]{%1$d}( [A-Za-z0-9_-]{%1$d})* :$/', $hashLength), $header->value);
        // Tags sharing a token take one slot, so a few more tags than slots fit.
        self::assertGreaterThanOrEqual($fits, count($header->included));
        self::assertSame($fits, count(explode(' ', trim($header->value, ': '))));
    }

    public function testPurgeValueMatchesOnlyResponsesCarryingTheTag(): void
    {
        $format = new HashedTagList();
        $header = $format->header(['node:1', 'node:2', 'node:3']);

        // fnmatch stands in for Bunny's wildcard matching.
        foreach (['node:1', 'node:2', 'node:3'] as $tag) {
            self::assertTrue(fnmatch($format->purgeValue($tag), $header->value), $tag);
        }
        self::assertFalse(fnmatch($format->purgeValue('node:4'), $header->value));
    }

    public function testTokensAreStableUrlSafeBase64(): void
    {
        $format = new HashedTagList(hashLength: 8);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{8}$/', $format->token('node:1'));
        self::assertSame($format->token('node:1'), $format->token('node:1'));
        self::assertSame(substr($format->token('node:1'), 0, 5), (new HashedTagList())->token('node:1'));
    }

    public function testNoTagsGivesAnEmptyHeader(): void
    {
        self::assertTrue((new HashedTagList())->header([])->isEmpty());
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'hash too short' => [2, 1024, ':'];
        yield 'hash too long' => [9, 1024, ':'];
        yield 'empty guard' => [5, 1024, ''];
        yield 'guard with space' => [5, 1024, ': '];
        yield 'guard with wildcard' => [5, 1024, '*'];
        yield 'too small for one tag' => [5, 8, ':'];
    }

    #[DataProvider('invalidOptions')]
    public function testRejectsInvalidOptions(int $hashLength, int $maxBytes, string $guard): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HashedTagList($hashLength, $maxBytes, $guard);
    }
}
