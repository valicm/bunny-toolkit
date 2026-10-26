<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Tests\Unit\CacheTag;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Valicm\BunnyToolkit\CacheTag\SingleTag;

#[CoversClass(SingleTag::class)]
final class SingleTagTest extends TestCase
{
    public function testHeaderIsTheFirstTagAsIs(): void
    {
        $header = (new SingleTag())->header(['auction_lot:5:42', 'node:1', 'auction_lot:5:42', 'node:1']);

        self::assertSame('auction_lot:5:42', $header->value);
        self::assertSame(['auction_lot:5:42'], $header->included);
        self::assertSame(['node:1'], $header->dropped);
    }

    public function testPurgeValueIsTheTagItself(): void
    {
        self::assertSame('auction_lot:5:42', (new SingleTag())->purgeValue('auction_lot:5:42'));
    }

    public function testNoTagsGivesAnEmptyHeader(): void
    {
        self::assertTrue((new SingleTag())->header([])->isEmpty());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTags(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['auction lot'];
        yield 'wildcard' => ['auction_lot:*'];
        yield 'non-ASCII' => ['lot:č'];
        yield 'too long' => [str_repeat('a', 1025)];
    }

    #[DataProvider('invalidTags')]
    public function testRejectsTagsBunnyCouldMisread(string $tag): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SingleTag())->purgeValue($tag);
    }
}
