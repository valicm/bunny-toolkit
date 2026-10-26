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

        self::assertMatchesRegularExpression('/^: [0-9a-f]{12} [0-9a-f]{12} :$/', $header->value);
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
        // Guards and spaces take 4 bytes, the first token 12 and each later one 13.
        $format = new HashedTagList(hashLength: 12, maxBytes: 29);

        $header = $format->header(['a', 'b', 'c', 'd']);

        self::assertSame(['a', 'b'], $header->included);
        self::assertSame(['c', 'd'], $header->dropped);
        self::assertSame(29, strlen($header->value));
    }

    public function testDefaultHeaderStaysWithinBunnysLimit(): void
    {
        $tags = array_map(static fn(int $i): string => 'node:' . $i, range(1, 200));

        $header = (new HashedTagList())->header($tags);

        self::assertLessThanOrEqual(1024, strlen($header->value));
        self::assertCount(78, $header->included);
        self::assertCount(122, $header->dropped);
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

    public function testNoTagsGivesAnEmptyHeader(): void
    {
        self::assertTrue((new HashedTagList())->header([])->isEmpty());
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'hash too short' => [5, 1024, ':'];
        yield 'hash too long' => [33, 1024, ':'];
        yield 'empty guard' => [12, 1024, ''];
        yield 'guard with space' => [12, 1024, ': '];
        yield 'guard with wildcard' => [12, 1024, '*'];
        yield 'too small for one tag' => [12, 15, ':'];
    }

    #[DataProvider('invalidOptions')]
    public function testRejectsInvalidOptions(int $hashLength, int $maxBytes, string $guard): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HashedTagList($hashLength, $maxBytes, $guard);
    }
}
