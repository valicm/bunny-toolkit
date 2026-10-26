<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Tests\Unit\Generator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Valicm\BunnyToolkit\Generator\Naming;

#[CoversClass(Naming::class)]
final class NamingTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function classNames(): iterable
    {
        yield 'words' => ['Pull Zone', 'PullZone'];
        yield 'acronym' => ['WAF', 'Waf'];
        yield 'acronym prefix' => ['DNS Zone', 'DnsZone'];
        yield 'version in parentheses' => ['Database (v2)', 'DatabaseV2'];
        yield 'camel case' => ['ContainerRegistries', 'ContainerRegistries'];
        yield 'reserved' => ['List', 'ListResource'];
    }

    #[DataProvider('classNames')]
    public function testClassNames(string $tag, string $expected): void
    {
        self::assertSame($expected, Naming::className($tag));
    }

    public function testTypeNamesKeepTheSchemaName(): void
    {
        self::assertSame('ForceSSLRequestModel', Naming::typeName('ForceSSLRequestModel'));
        self::assertSame('PaginationListModelPullZone', Naming::typeName('PaginationListModel<PullZone>'));
    }

    /**
     * @return iterable<string, array{?string, ?string, string, bool}>
     */
    public static function methodNames(): iterable
    {
        yield 'short summary beats an action name' => ['PullZonePublic_Index', 'Get Pull Zone', 'getPullZone', false];
        yield 'slash reads as or' => ['PullZonePublic_AddEdgeRule', 'Add/Update Edge Rule', 'addOrUpdateEdgeRule', false];
        yield 'articles dropped' => ['PullZonePublic_CheckAvailability', 'Check the pull zone availability', 'checkPullZoneAvailability', false];
        yield 'long summary falls back to operationId' => ['Get Access Lists', 'Get all Access Lists available for a Shield Zone', 'getAccessLists', false];
        yield 'operationId suffix after underscore' => ['EdgeScriptStatisticsEndpoint_GetEdgeScriptStatisticsEndpoint', null, 'getEdgeScriptStatistics', false];
        yield 'preferred operationId' => ['Update Custom WAF Rule', 'Update an existing rule', 'updateCustomWafRule', true];
        yield 'summary only' => [null, '(preview) Fork', 'previewFork', false];
    }

    #[DataProvider('methodNames')]
    public function testMethodNames(?string $operationId, ?string $summary, string $expected, bool $preferOperationId): void
    {
        self::assertSame($expected, Naming::method($operationId, $summary, 'get', '/x', $preferOperationId));
    }

    public function testMethodNameFromPathWhenNothingElse(): void
    {
        self::assertSame('getPullzoneId', Naming::method(null, null, 'get', '/pullzone/{id}'));
    }

    public function testUniqueAddsANumber(): void
    {
        $taken = [];

        self::assertSame('id', Naming::unique('id', $taken));
        self::assertSame('id2', Naming::unique('id', $taken));
        self::assertSame('Id3', Naming::unique('Id', $taken));
    }
}
