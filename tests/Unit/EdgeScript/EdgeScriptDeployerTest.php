<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Tests\Unit\EdgeScript;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Valicm\BunnyToolkit\Api\EdgeScripting\EdgeScriptingApi;
use Valicm\BunnyToolkit\EdgeScript\EdgeScriptDeployer;
use Valicm\BunnyToolkit\Tests\Support\FakeHttpClient;
use Valicm\BunnyToolkit\Tests\Support\TestApi;

#[CoversClass(EdgeScriptDeployer::class)]
final class EdgeScriptDeployerTest extends TestCase
{
    public function testSetsVariablesAndSecretsBeforeCodeThenPublishes(): void
    {
        $http = (new FakeHttpClient())->respondJson(200, ['Id' => 1])->respondJson(200, ['Id' => 2])->respond(204)->respond(204);
        $deployer = new EdgeScriptDeployer(new EdgeScriptingApi(TestApi::client($http, EdgeScriptingApi::BASE_URL)));

        $deployer->deploy(
            7,
            'export default {};',
            variables: ['ORIGIN_HOST' => 'www.example.com'],
            secrets: ['ORIGIN_SECRET' => 's3cr3t'],
            releaseNote: 'Deploy abc123',
        );

        self::assertSame([
            'PUT /compute/script/7/variables',
            'PUT /compute/script/7/secrets',
            'POST /compute/script/7/code',
            'POST /compute/script/7/publish',
        ], array_map(self::describe(...), $http->requests));
        self::assertSame(['Name' => 'ORIGIN_HOST', 'DefaultValue' => 'www.example.com'], FakeHttpClient::jsonBody($http->requests[0]));
        self::assertSame(['Name' => 'ORIGIN_SECRET', 'Secret' => 's3cr3t'], FakeHttpClient::jsonBody($http->requests[1]));
        self::assertSame(['Code' => 'export default {};'], FakeHttpClient::jsonBody($http->requests[2]));
        self::assertSame(['Note' => 'Deploy abc123'], FakeHttpClient::jsonBody($http->requests[3]));
    }

    public function testPublishesWithoutANote(): void
    {
        $http = (new FakeHttpClient())->respond(204)->respond(204);
        $deployer = new EdgeScriptDeployer(new EdgeScriptingApi(TestApi::client($http, EdgeScriptingApi::BASE_URL)));

        $deployer->deploy(7, 'export default {};');

        self::assertSame('{"Note":null}', (string) $http->requests[1]->getBody());
    }

    public function testUploadsWithoutPublishing(): void
    {
        $http = (new FakeHttpClient())->respond(204);
        $deployer = new EdgeScriptDeployer(new EdgeScriptingApi(TestApi::client($http, EdgeScriptingApi::BASE_URL)));

        $deployer->deploy(7, 'export default {};', publish: false);

        self::assertSame(['POST /compute/script/7/code'], array_map(self::describe(...), $http->requests));
    }

    private static function describe(RequestInterface $request): string
    {
        return $request->getMethod() . ' ' . $request->getUri()->getPath();
    }
}
