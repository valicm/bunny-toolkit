<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Tests\Unit\Generator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Valicm\BunnyToolkit\Generator\Json;
use Valicm\BunnyToolkit\Generator\SpecGenerator;

#[CoversClass(SpecGenerator::class)]
#[CoversClass(Json::class)]
final class SpecGeneratorTest extends TestCase
{
    private const CONFIG = [
        'file' => 'specs/test.json',
        'source' => 'https://example.com/spec.json',
        'namespace' => 'Test',
        'title' => 'Test API',
    ];

    /**
     * @return array<array-key, mixed>
     */
    private static function spec(): array
    {
        return [
            'servers' => [['url' => 'https://api.example.com/v1/']],
            'tags' => [['name' => 'Pull Zone', 'description' => 'Manage pull zones.']],
            'paths' => [
                '/pullzone/{id}' => [
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                    'get' => [
                        'tags' => ['Pull Zone'],
                        'summary' => 'Get Pull Zone',
                        'parameters' => [
                            ['name' => 'includeCertificate', 'in' => 'query', 'schema' => ['type' => 'boolean']],
                            ['name' => 'AccessKey', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
                        ],
                        'responses' => ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/PullZoneModel']]]]],
                    ],
                    'post' => [
                        'tags' => ['Pull Zone'],
                        'summary' => 'Update Pull Zone',
                        'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/PullZoneModel']]]],
                        'responses' => ['204' => ['description' => 'Updated']],
                    ],
                ],
                '/files/{path}' => [
                    'put' => [
                        'tags' => ['Files'],
                        'summary' => 'Upload File',
                        'parameters' => [['name' => 'path', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                        'requestBody' => ['content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]],
                        'responses' => ['201' => ['description' => 'Created']],
                    ],
                ],
            ],
            'components' => ['schemas' => [
                'PullZoneModel' => ['type' => 'object', 'properties' => ['Id' => ['type' => 'integer'], 'Name' => ['type' => 'string', 'nullable' => true]]],
            ]],
        ];
    }

    public function testGeneratesApiResourcesAndTypes(): void
    {
        $files = (new SpecGenerator(self::spec(), self::CONFIG, 'App\\Api'))->generate();

        self::assertSame(['TestApi.php', 'Resource/Files.php', 'Resource/PullZone.php', 'Types.php'], array_keys($files));
        self::assertStringContainsString("public const BASE_URL = 'https://api.example.com/v1';", $files['TestApi.php']);
        self::assertStringContainsString('public function pullZone(): Resource\\PullZone', $files['TestApi.php']);

        $pullZone = $files['Resource/PullZone.php'];
        self::assertStringContainsString('@phpstan-import-type PullZoneModel from \\App\\Api\\Test\\Types', $pullZone);
        self::assertStringContainsString('public function getPullZone(int $id, ?bool $includeCertificate = null): mixed', $pullZone);
        self::assertStringContainsString("query: ['includeCertificate' => \$includeCertificate]", $pullZone);
        self::assertStringNotContainsString('accessKey', $pullZone, 'Auth headers come from the client, not method parameters.');
        self::assertStringContainsString('@return PullZoneModel', $pullZone);
        self::assertStringContainsString('public function updatePullZone(int $id, array $body): void', $pullZone);
        self::assertStringContainsString('json: (object) $body', $pullZone);

        self::assertStringContainsString('public function uploadFile(string $path, StreamInterface|string|null $body = null): void', $files['Resource/Files.php']);
        self::assertStringContainsString('raw: $body', $files['Resource/Files.php']);

        self::assertStringContainsString(" * @phpstan-type PullZoneModel array{\n *     Id?: int,\n *     Name?: string|null,\n * }", $files['Types.php']);
    }

    public function testGeneratedCodeIsValidPhp(): void
    {
        foreach ((new SpecGenerator(self::spec(), self::CONFIG, 'App\\Api'))->generate() as $path => $contents) {
            $tokens = \PhpToken::tokenize($contents, \TOKEN_PARSE);
            self::assertNotEmpty($tokens, $path);
        }
    }

    public function testNameCollisionsFailGeneration(): void
    {
        $spec = self::spec();
        $paths = is_array($spec['paths']) ? $spec['paths'] : [];
        $paths['/pullzone/{id}/v2'] = ['get' => ['tags' => ['Pull Zone'], 'summary' => 'Get Pull Zone', 'responses' => []]];
        $spec['paths'] = $paths;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PullZone::getpullzone() <- GET /pullzone/{id}, GET /pullzone/{id}/v2');

        (new SpecGenerator($spec, self::CONFIG, 'App\\Api'))->generate();
    }

    public function testRecordsThePublicSurface(): void
    {
        $generator = new SpecGenerator(self::spec(), self::CONFIG, 'App\\Api');
        $generator->generate();

        self::assertSame([
            'operations' => [
                'GET /pullzone/{id}' => [
                    'method' => 'PullZone::getPullZone',
                    'params' => ['path:id' => 'int $id', 'query:includeCertificate' => '?bool $includeCertificate = null'],
                    'returns' => 'mixed',
                ],
                'POST /pullzone/{id}' => [
                    'method' => 'PullZone::updatePullZone',
                    'params' => ['path:id' => 'int $id', 'body' => 'array $body'],
                    'returns' => 'void',
                ],
                'PUT /files/{path}' => [
                    'method' => 'Files::uploadFile',
                    'params' => ['path:path' => 'string $path', 'body' => 'StreamInterface|string|null $body = null'],
                    'returns' => 'void',
                ],
            ],
            'enums' => [],
        ], $generator->surface());
    }

    public function testLockedSurfaceSurvivesSpecChanges(): void
    {
        $locked = [
            'operations' => [
                'GET /pullzone/{id}' => [
                    'method' => 'PullZone::getPullZone',
                    'params' => ['path:id' => 'int $id', 'query:includeCertificate' => '?bool $includeCertificate = null'],
                    'returns' => 'mixed',
                ],
                'POST /pullzone/{id}' => [
                    'method' => 'PullZone::updatePullZone',
                    'params' => ['path:id' => 'int $id', 'body' => 'array $body'],
                    'returns' => 'void',
                ],
            ],
            'enums' => [],
        ];
        // Bunny retags both operations, rewrites a summary, adds a parameter
        // whose name clashes with a locked one, and makes the body optional.
        $spec = [
            'paths' => [
                '/pullzone/{id}' => [
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                    'get' => [
                        'tags' => ['Zones'],
                        'summary' => 'Get pull zone details',
                        'parameters' => [
                            ['name' => 'include-certificate', 'in' => 'header', 'schema' => ['type' => 'string']],
                            ['name' => 'includeCertificate', 'in' => 'query', 'schema' => ['type' => 'boolean']],
                        ],
                        'responses' => ['200' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]]],
                    ],
                    'post' => [
                        'tags' => ['Zones'],
                        'summary' => 'Update Pull Zone',
                        'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
                        'responses' => ['204' => ['description' => 'Updated']],
                    ],
                ],
            ],
        ];

        $generator = new SpecGenerator($spec, self::CONFIG, 'App\\Api', $locked);
        $files = $generator->generate();

        self::assertArrayNotHasKey('Resource/Zones.php', $files);
        $pullZone = $files['Resource/PullZone.php'];
        self::assertStringContainsString('public function getPullZone(int $id, ?bool $includeCertificate = null, ?string $includeCertificate2 = null): mixed', $pullZone);
        self::assertStringContainsString("query: ['includeCertificate' => \$includeCertificate], headers: ['include-certificate' => \$includeCertificate2]", $pullZone);
        self::assertStringContainsString('public function updatePullZone(int $id, array $body): void', $pullZone, 'A parameter Bunny made optional stays required.');
        self::assertStringContainsString('json: (object) $body', $pullZone);
        self::assertSame(['path:id', 'query:includeCertificate', 'header:include-certificate'], array_keys($generator->surface()['operations']['GET /pullzone/{id}']['params']));
    }

    public function testDeprecatesOperationsKeptAfterBunnyRemovedThem(): void
    {
        $spec = self::spec();
        $paths = is_array($spec['paths']) ? $spec['paths'] : [];
        $paths['/legacy'] = ['get' => ['tags' => ['Pull Zone'], 'summary' => 'Get Legacy', 'x-removed-on' => '2026-10-26', 'responses' => []]];
        $spec['paths'] = $paths;

        $generator = new SpecGenerator($spec, self::CONFIG, 'App\\Api');
        $files = $generator->generate();

        self::assertStringContainsString(
            "@deprecated Removed from the bunny.net spec on 2026-10-26; kept until the next major version.\n     */\n    public function getLegacy(): void",
            $files['Resource/PullZone.php'],
        );
        $surface = $generator->surface();
        self::assertTrue($surface['operations']['GET /legacy']['deprecated'] ?? false);
        self::assertArrayNotHasKey('deprecated', $surface['operations']['GET /pullzone/{id}']);
    }

    public function testKeepsLockedEnumCasesBunnyRemoved(): void
    {
        $spec = [
            'paths' => [],
            'components' => ['schemas' => [
                'Status' => ['type' => 'string', 'enum' => ['active', 'stopped']],
            ]],
        ];
        $locked = [
            'operations' => [],
            'enums' => [
                'Status' => ['Active' => 'active', 'Paused' => 'paused', 'Halted' => 'stopped'],
                'Region' => ['Eu' => 1, 'Us' => 2],
            ],
        ];

        $generator = new SpecGenerator($spec, self::CONFIG, 'App\\Api', $locked);
        $files = $generator->generate();

        $status = $files['Enum/Status.php'];
        self::assertStringContainsString("    case Stopped = 'stopped';\n", $status);
        self::assertStringContainsString("@deprecated Removed from the bunny.net spec; kept until the next major version.\n     */\n    case Paused = 'paused';", $status);
        self::assertStringContainsString("@deprecated Removed from the bunny.net spec; use Stopped instead. Kept until the next major version.\n     */\n    public const Halted = self::Stopped;", $status);
        self::assertStringContainsString(" * @deprecated Removed from the bunny.net spec; kept until the next major version.\n */\nenum Region: int\n{\n    case Eu = 1;\n    case Us = 2;\n}", $files['Enum/Region.php']);

        $surface = $generator->surface();
        self::assertSame(['Active' => 'active', 'Stopped' => 'stopped', 'Paused' => 'paused', 'Halted' => 'stopped'], $surface['enums']['Status']);
        self::assertSame(['Region' => ['Eu', 'Us'], 'Status' => ['Paused', 'Halted']], $surface['deprecatedEnumCases'] ?? []);

        // Loading the enums catches duplicate values and invalid constants.
        $namespace = 'namespace App\\Api\\Test\\Enum' . bin2hex(random_bytes(4)) . ';';
        foreach (['Enum/Status.php', 'Enum/Region.php'] as $file) {
            $path = (string) tempnam(sys_get_temp_dir(), 'enum');
            file_put_contents($path, str_replace('namespace App\\Api\\Test\\Enum;', $namespace, $files[$file]));
            try {
                require $path;
            } finally {
                unlink($path);
            }
        }
    }

    public function testDropsRemovedEnumCasesForAMajorVersion(): void
    {
        $spec = ['paths' => [], 'components' => ['schemas' => ['Status' => ['type' => 'string', 'enum' => ['active']]]]];
        $locked = ['operations' => [], 'enums' => ['Status' => ['Active' => 'active', 'Paused' => 'paused'], 'Region' => ['Eu' => 1]]];

        $generator = new SpecGenerator($spec, self::CONFIG, 'App\\Api', $locked, keepRemoved: false);
        $files = $generator->generate();

        self::assertStringNotContainsString('Paused', $files['Enum/Status.php']);
        self::assertArrayNotHasKey('Enum/Region.php', $files);
        self::assertArrayNotHasKey('deprecatedEnumCases', $generator->surface());
    }

    public function testMarksExperimentalApis(): void
    {
        $files = (new SpecGenerator(self::spec(), self::CONFIG + ['experimental' => true], 'App\\Api'))->generate();

        self::assertStringContainsString('@experimental', $files['TestApi.php']);
        self::assertStringContainsString('@experimental', $files['Resource/PullZone.php']);
    }

    public function testOverridesNamesAndResponseKinds(): void
    {
        $spec = self::spec();
        $config = self::CONFIG + [
            'methodNames' => ['GET /pullzone/{id}' => 'fetch'],
            'responseKinds' => ['PUT /files/{path}' => 'stream'],
            'slashParams' => ['path'],
        ];

        $files = (new SpecGenerator($spec, $config, 'App\\Api'))->generate();

        self::assertStringContainsString('public function fetch(int $id', $files['Resource/PullZone.php']);
        self::assertStringContainsString('): StreamInterface', $files['Resource/Files.php']);
        self::assertStringContainsString("keepSlashes: ['path']", $files['Resource/Files.php']);
    }

    public function testDeclaresAResponseSchemaTheSpecLeavesOut(): void
    {
        $config = self::CONFIG + ['responseSchemas' => ['POST /pullzone/{id}' => 'PullZoneModel']];

        $pullZone = (new SpecGenerator(self::spec(), $config, 'App\\Api'))->generate()['Resource/PullZone.php'];

        self::assertStringContainsString('public function updatePullZone(int $id, array $body): mixed', $pullZone);
        self::assertStringContainsString("\$data = \$this->client->json('POST', ", $pullZone);
        self::assertSame(2, substr_count($pullZone, '@return PullZoneModel'));
    }

    public function testRefusesAResponseSchemaTheSpecDoesNotDefine(): void
    {
        $config = self::CONFIG + ['responseSchemas' => ['POST /pullzone/{id}' => 'Missing']];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('names Missing');

        (new SpecGenerator(self::spec(), $config, 'App\\Api'))->generate();
    }

    public function testBaseUrlStandsInForMissingServers(): void
    {
        $spec = self::spec();
        unset($spec['servers']);

        $files = (new SpecGenerator($spec, self::CONFIG + ['baseUrl' => 'https://logs.example.com/'], 'App\\Api'))->generate();

        self::assertStringContainsString("public const BASE_URL = 'https://logs.example.com';", $files['TestApi.php']);
    }
}
