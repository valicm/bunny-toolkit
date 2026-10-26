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
}
