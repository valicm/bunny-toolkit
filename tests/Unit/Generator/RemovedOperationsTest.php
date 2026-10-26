<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Tests\Unit\Generator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Valicm\BunnyToolkit\Generator\Json;
use Valicm\BunnyToolkit\Generator\RemovedOperations;

#[CoversClass(RemovedOperations::class)]
#[CoversClass(Json::class)]
final class RemovedOperationsTest extends TestCase
{
    private const LOCKED = ['GET /pullzone/{id}', 'GET /pullzone/{id}/stats', 'DELETE /pullzone/{id}/stats', 'GET /legacy'];

    /**
     * @return array<array-key, mixed>
     */
    private static function oldSpec(): array
    {
        return [
            'paths' => [
                '/pullzone/{id}' => [
                    'get' => ['summary' => 'Get Pull Zone', 'responses' => []],
                ],
                '/pullzone/{id}/stats' => [
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                        ['name' => 'from', 'in' => 'query', 'schema' => ['type' => 'string']],
                    ],
                    'get' => [
                        'summary' => 'Get Statistics',
                        'parameters' => [['name' => 'from', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']]],
                        'responses' => ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Stats']]]]],
                    ],
                    'delete' => ['summary' => 'Reset Statistics', 'responses' => []],
                ],
                '/unreleased' => [
                    'get' => ['summary' => 'Not In The Lock', 'responses' => []],
                ],
            ],
            'components' => ['schemas' => [
                'Stats' => ['type' => 'object', 'properties' => ['Series' => ['$ref' => '#/components/schemas/Series'], 'Zone' => ['$ref' => '#/components/schemas/Zone']]],
                'Series' => ['type' => 'array', 'items' => ['type' => 'number']],
                'Zone' => ['type' => 'object'],
            ]],
        ];
    }

    /**
     * Bunny drops GET /pullzone/{id}/stats, its Stats and Series schemas, and /unreleased.
     *
     * @return array<array-key, mixed>
     */
    private static function newSpec(): array
    {
        return [
            'paths' => [
                '/pullzone/{id}' => ['get' => ['summary' => 'Get Pull Zone', 'responses' => []]],
                '/pullzone/{id}/stats' => ['delete' => ['summary' => 'Reset Statistics', 'responses' => []]],
            ],
            'components' => ['schemas' => ['Zone' => ['type' => 'object']]],
        ];
    }

    public function testKeepsLockedOperationsBunnyRemoved(): void
    {
        $kept = RemovedOperations::update(self::oldSpec(), self::newSpec(), [], self::LOCKED, '2026-10-26');

        self::assertSame([
            'paths' => [
                '/pullzone/{id}/stats' => [
                    'get' => [
                        'summary' => 'Get Statistics',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                            ['name' => 'from', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
                        ],
                        'responses' => ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Stats']]]]],
                        'x-removed-on' => '2026-10-26',
                    ],
                ],
            ],
            // Zone is still in the new spec, so only the schemas Bunny dropped are kept.
            'components' => ['schemas' => [
                'Series' => ['type' => 'array', 'items' => ['type' => 'number']],
                'Stats' => ['type' => 'object', 'properties' => ['Series' => ['$ref' => '#/components/schemas/Series'], 'Zone' => ['$ref' => '#/components/schemas/Zone']]],
            ]],
        ], $kept);
    }

    public function testKeepsEarlierOperationsUntilBunnyBringsThemBack(): void
    {
        $earlier = [
            'paths' => [
                '/legacy' => ['get' => ['summary' => 'Legacy', 'responses' => [], 'x-removed-on' => '2026-01-01']],
                '/pullzone/{id}' => ['post' => ['summary' => 'Restored', 'responses' => [], 'x-removed-on' => '2026-01-01']],
            ],
        ];
        $new = self::newSpec();
        $new['paths'] = [
            '/pullzone/{id}' => [
                'get' => ['summary' => 'Get Pull Zone', 'responses' => []],
                'post' => ['summary' => 'Restored', 'responses' => []],
            ],
            '/pullzone/{id}/stats' => ['delete' => ['summary' => 'Reset Statistics', 'responses' => []]],
        ];

        $kept = RemovedOperations::update(self::newSpec(), $new, $earlier, self::LOCKED, '2026-10-26');

        self::assertSame(['/legacy'], array_keys(Json::map($kept, 'paths')));
        self::assertSame('2026-01-01', Json::str(Json::map(Json::map($kept, 'paths'), '/legacy')['get'] ?? null, 'x-removed-on'));
    }

    public function testKeepsNothingWhenNothingWasRemoved(): void
    {
        self::assertSame([], RemovedOperations::update(self::newSpec(), self::newSpec(), [], self::LOCKED, '2026-10-26'));
    }

    public function testMergesKeptOperationsIntoTheSpec(): void
    {
        $kept = RemovedOperations::update(self::oldSpec(), self::newSpec(), [], self::LOCKED, '2026-10-26');

        $spec = RemovedOperations::merge(self::newSpec(), $kept);

        $stats = Json::map(Json::map($spec, 'paths'), '/pullzone/{id}/stats');
        self::assertSame(['delete', 'get'], array_keys($stats));
        self::assertSame(['Zone', 'Series', 'Stats'], array_keys(Json::map(Json::map($spec, 'components'), 'schemas')));
    }

    public function testSpecWinsOverAKeptCopy(): void
    {
        $kept = ['paths' => ['/pullzone/{id}' => ['get' => ['summary' => 'Old copy']]]];

        $spec = RemovedOperations::merge(self::newSpec(), $kept);

        self::assertSame('Get Pull Zone', Json::str(Json::map(Json::map($spec, 'paths'), '/pullzone/{id}')['get'] ?? null, 'summary'));
    }

    public function testFileLivesNextToTheSpec(): void
    {
        self::assertSame('specs/removed/core.json', RemovedOperations::file('specs/core.json'));
    }
}
