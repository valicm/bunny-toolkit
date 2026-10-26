<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Tests\Unit\Generator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Valicm\BunnyToolkit\Generator\ApiSurface;
use Valicm\BunnyToolkit\Generator\Json;

/**
 * @phpstan-import-type Surface from ApiSurface
 */
#[CoversClass(ApiSurface::class)]
#[CoversClass(Json::class)]
final class ApiSurfaceTest extends TestCase
{
    private const LIST_PULL_ZONES = [
        'method' => 'PullZone::listPullZones',
        'params' => ['query:page' => '?int $page = null', 'query:search' => '?string $search = null'],
        'returns' => 'mixed',
    ];

    /**
     * @return Surface
     */
    private static function surface(): array
    {
        return [
            'operations' => [
                'GET /pullzone' => self::LIST_PULL_ZONES,
                'POST /pullzone/{id}/purgeCache' => [
                    'method' => 'PullZone::purgeCache',
                    'params' => ['path:id' => 'int $id', 'body' => '?array $body = null'],
                    'returns' => 'void',
                ],
            ],
            'enums' => ['Status' => ['Active' => 'active', 'Paused' => 'paused']],
        ];
    }

    /**
     * @param array{method?: string, params?: array<string, string>, returns?: string} $changes
     * @return Surface
     */
    private static function withListPullZones(array $changes): array
    {
        $surface = self::surface();
        $surface['operations']['GET /pullzone'] = [
            'method' => $changes['method'] ?? self::LIST_PULL_ZONES['method'],
            'params' => $changes['params'] ?? self::LIST_PULL_ZONES['params'],
            'returns' => $changes['returns'] ?? self::LIST_PULL_ZONES['returns'],
        ];

        return $surface;
    }

    public function testSameSurfaceHasNoChanges(): void
    {
        self::assertSame([], ApiSurface::diff(self::surface(), self::surface()));
    }

    public function testAdditionsAreNotBreaking(): void
    {
        $new = self::surface();
        $new['operations']['GET /pullzone/{id}'] = ['method' => 'PullZone::getPullZone', 'params' => ['path:id' => 'int $id'], 'returns' => 'mixed'];
        $new['operations']['POST /pullzone/{id}/purgeCache'] = [
            'method' => 'PullZone::purgeCache',
            'params' => ['path:id' => 'int $id', 'body' => '?array $body = null', 'query:async' => '?bool $async = null'],
            'returns' => 'mixed',
            'deprecated' => true,
        ];
        $new['enums']['Status']['Disabled'] = 'disabled';
        $new['enums']['Region'] = ['Eu' => 'EU'];

        self::assertSame([
            ['breaking' => false, 'message' => 'PullZone::purgeCache() optional parameter $async added'],
            ['breaking' => false, 'message' => 'PullZone::purgeCache() now returns mixed'],
            ['breaking' => false, 'message' => 'PullZone::purgeCache() deprecated'],
            ['breaking' => false, 'message' => 'PullZone::getPullZone() added (GET /pullzone/{id})'],
            ['breaking' => false, 'message' => 'Status::Disabled added'],
            ['breaking' => false, 'message' => 'enum Region added'],
        ], ApiSurface::diff(self::surface(), $new));
    }

    /**
     * @return iterable<string, array{Surface, string}>
     */
    public static function breakingChanges(): iterable
    {
        $s = self::surface();
        unset($s['operations']['GET /pullzone']);
        yield 'operation removed' => [$s, 'PullZone::listPullZones() removed (GET /pullzone)'];

        yield 'method renamed' => [
            self::withListPullZones(['method' => 'PullZone::listZones']),
            'PullZone::listPullZones() renamed to PullZone::listZones()',
        ];
        yield 'parameter removed' => [
            self::withListPullZones(['params' => ['query:page' => '?int $page = null']]),
            'PullZone::listPullZones() parameter $search removed',
        ];
        yield 'parameter renamed' => [
            self::withListPullZones(['params' => ['query:page' => '?int $page = null', 'query:search' => '?string $query = null']]),
            'PullZone::listPullZones() parameter $search renamed to $query',
        ];
        yield 'parameter type changed' => [
            self::withListPullZones(['params' => ['query:page' => '?string $page = null', 'query:search' => '?string $search = null']]),
            'PullZone::listPullZones() parameter $page changed type from int to string',
        ];
        yield 'parameter now required' => [
            self::withListPullZones(['params' => ['query:page' => 'int $page', 'query:search' => '?string $search = null']]),
            'PullZone::listPullZones() parameter $page is now required',
        ];
        yield 'new required parameter' => [
            self::withListPullZones(['params' => [...self::LIST_PULL_ZONES['params'], 'query:type' => 'string $type']]),
            'PullZone::listPullZones() new required parameter $type',
        ];
        yield 'parameter order changed' => [
            self::withListPullZones(['params' => array_reverse(self::LIST_PULL_ZONES['params'])]),
            'PullZone::listPullZones() parameter order changed',
        ];
        yield 'return type changed' => [
            self::withListPullZones(['returns' => 'string']),
            'PullZone::listPullZones() return type changed from mixed to string',
        ];

        $s = self::surface();
        unset($s['enums']['Status']);
        yield 'enum removed' => [$s, 'enum Status removed'];

        $s = self::surface();
        unset($s['enums']['Status']['Paused']);
        yield 'enum case removed' => [$s, 'Status::Paused removed'];

        $s = self::surface();
        $s['enums']['Status']['Paused'] = 'PAUSED';
        yield 'enum value changed' => [$s, "Status::Paused changed from 'paused' to 'PAUSED'"];
    }

    /**
     * @param Surface $new
     */
    #[DataProvider('breakingChanges')]
    public function testBreakingChanges(array $new, string $message): void
    {
        self::assertContains(['breaking' => true, 'message' => $message], ApiSurface::diff(self::surface(), $new));
    }

    public function testParameterRemovedFromTheEndKeepsTheOrder(): void
    {
        $new = self::withListPullZones(['params' => ['query:page' => '?int $page = null']]);

        self::assertSame(
            [['breaking' => true, 'message' => 'PullZone::listPullZones() parameter $search removed']],
            ApiSurface::diff(self::surface(), $new),
        );
    }

    public function testReportsWhenADeprecationIsLifted(): void
    {
        $old = self::surface();
        $old['operations']['GET /pullzone'] = [...self::LIST_PULL_ZONES, 'deprecated' => true];

        self::assertSame(
            [['breaking' => false, 'message' => 'PullZone::listPullZones() no longer deprecated']],
            ApiSurface::diff($old, self::surface()),
        );
    }

    public function testReportsDeprecatedEnumCases(): void
    {
        $new = self::surface();
        $new['enums']['Status']['Halted'] = 'halted';
        $new['deprecatedEnumCases'] = ['Status' => ['Paused']];

        self::assertSame(
            [['breaking' => false, 'message' => 'Status::Halted added'], ['breaking' => false, 'message' => 'Status::Paused deprecated']],
            ApiSurface::diff(self::surface(), $new),
        );
        self::assertSame(
            [['breaking' => false, 'message' => 'Status::Paused no longer deprecated']],
            ApiSurface::diff($new, [...$new, 'deprecatedEnumCases' => []]),
        );
    }

    public function testReportsAWholeEnumKeptAsDeprecated(): void
    {
        $new = self::surface();
        $new['deprecatedEnumCases'] = ['Status' => ['Active', 'Paused']];

        self::assertSame([['breaking' => false, 'message' => 'enum Status deprecated']], ApiSurface::diff(self::surface(), $new));
        self::assertSame([], ApiSurface::diff($new, $new));
    }

    public function testParsesParameters(): void
    {
        self::assertSame(['type' => '?int', 'name' => 'page', 'optional' => true], ApiSurface::parseParam('?int $page = null'));
        self::assertSame(['type' => 'StreamInterface|string|null', 'name' => 'body', 'optional' => true], ApiSurface::parseParam('StreamInterface|string|null $body = null'));
        self::assertSame(['type' => 'int', 'name' => 'id', 'optional' => false], ApiSurface::parseParam('int $id'));
    }

    public function testReadsTheLockFileFormat(): void
    {
        $surface = self::surface();
        $surface['operations']['GET /pullzone'] = [...self::LIST_PULL_ZONES, 'deprecated' => true];
        $surface['deprecatedEnumCases'] = ['Status' => ['Paused']];
        $json = json_decode((string) json_encode($surface), true);

        self::assertSame($surface, ApiSurface::fromJson($json));
        self::assertSame(['operations' => [], 'enums' => []], ApiSurface::fromJson([]));
    }

    public function testRejectsInvalidLockEntries(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid entry for "GET /pullzone"');

        ApiSurface::fromJson(['operations' => ['GET /pullzone' => ['params' => []]]]);
    }
}
