<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Tests\Unit\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Valicm\BunnyToolkit\Api\Core\Enum\EdgeRuleActionType;
use Valicm\BunnyToolkit\Http\ApiClient;
use Valicm\BunnyToolkit\Http\ApiException;
use Valicm\BunnyToolkit\Http\RateLimitedException;
use Valicm\BunnyToolkit\Http\UnexpectedResponseException;
use Valicm\BunnyToolkit\Tests\Support\FakeClock;
use Valicm\BunnyToolkit\Tests\Support\FakeHttpClient;
use Valicm\BunnyToolkit\Tests\Support\TestApi;

#[CoversClass(ApiClient::class)]
#[CoversClass(ApiException::class)]
#[CoversClass(RateLimitedException::class)]
final class ApiClientTest extends TestCase
{
    private FakeHttpClient $http;

    private FakeClock $clock;

    private ApiClient $api;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->clock = new FakeClock();
        $this->api = TestApi::client($this->http, 'https://api.bunny.net/mc/', $this->clock);
    }

    public function testSendsTheAccessKeyToTheBaseUrlWithItsPathPrefix(): void
    {
        $this->http->respondJson(200, ['Id' => 'app-1']);

        $data = $this->api->json('GET', '/apps/app-1');

        self::assertSame(['Id' => 'app-1'], $data);
        $request = $this->http->requests[0];
        self::assertSame('https://api.bunny.net/mc/apps/app-1', (string) $request->getUri());
        self::assertSame('secret-key', $request->getHeaderLine('AccessKey'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('valicm/bunny-toolkit', $request->getHeaderLine('User-Agent'));
    }

    public function testBuildsTheQueryString(): void
    {
        $this->http->respond(204);

        $this->api->send('GET', '/search', query: [
            'search' => 'a b&c',
            'page' => 2,
            'includeDeleted' => false,
            'skipped' => null,
            'ids' => [1, 2],
            'action' => EdgeRuleActionType::Redirect,
        ]);

        self::assertSame('search=a%20b%26c&page=2&includeDeleted=false&ids=1&ids=2&action=1', $this->http->requests[0]->getUri()->getQuery());
    }

    public function testEncodesPathParameters(): void
    {
        self::assertSame('/zones/a%2Fb/files', $this->api->path('/zones/{zone}/files', ['zone' => 'a/b']));
        self::assertSame(
            '/my-zone/images/2024%20q1/photo%231.jpg',
            $this->api->path('/{zone}/{path}/{file}', ['zone' => 'my-zone', 'path' => '/images/2024 q1/', 'file' => 'photo#1.jpg'], keepSlashes: ['path']),
        );
    }

    public function testSendsJsonBodies(): void
    {
        $this->http->respond(204)->respond(204);

        $this->api->send('POST', '/rules', json: (object) []);
        $this->api->send('POST', '/rules', json: ['Triggers' => [], 'ActionType' => EdgeRuleActionType::Redirect, 'Url' => 'https://example.com/a']);

        self::assertSame('{}', (string) $this->http->requests[0]->getBody());
        self::assertSame('application/json', $this->http->requests[0]->getHeaderLine('Content-Type'));
        self::assertSame('{"Triggers":[],"ActionType":1,"Url":"https://example.com/a"}', (string) $this->http->requests[1]->getBody());
    }

    public function testSendsRawBodies(): void
    {
        $this->http->respond(201)->respond(201);

        $this->api->send('PUT', '/zone/file.txt', raw: 'file contents');
        $this->api->send('PUT', '/zone/file.txt', raw: (new Psr17Factory())->createStream('streamed'));

        self::assertSame('application/octet-stream', $this->http->requests[0]->getHeaderLine('Content-Type'));
        self::assertSame('file contents', (string) $this->http->requests[0]->getBody());
        self::assertSame('streamed', (string) $this->http->requests[1]->getBody());
    }

    public function testOmitsTheBodyWhenThereIsNone(): void
    {
        $this->http->respond(204);

        $this->api->send('POST', '/pullzone/1/purgeCache');

        self::assertSame('', (string) $this->http->requests[0]->getBody());
        self::assertFalse($this->http->requests[0]->hasHeader('Content-Type'));
    }

    public function testEmptyJsonResponseIsNull(): void
    {
        $this->http->respond(200);

        self::assertNull($this->api->json('GET', '/apps'));
    }

    public function testInvalidJsonResponseIsReported(): void
    {
        $this->http->respond(200, '<html>');

        $this->expectException(UnexpectedResponseException::class);

        $this->api->json('GET', '/apps');
    }

    public function testTextAndStreamResponses(): void
    {
        $this->http->respond(200, "a,b\n1,2\n")->respond(200, 'binary');

        self::assertSame("a,b\n1,2\n", $this->api->text('POST', '/export'));
        self::assertSame('binary', (string) $this->api->stream('GET', '/zone/file.bin'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, ?string, ?string}>
     */
    public static function errorBodies(): iterable
    {
        yield 'Core' => [['ErrorKey' => 'pullzone.validation', 'Field' => 'OriginUrl', 'Message' => 'The origin URL is not valid.'], 'The origin URL is not valid.', 'pullzone.validation', 'OriginUrl'];
        yield 'problem details' => [['title' => 'Bad Request', 'detail' => 'Name is required.'], 'Name is required.', null, null];
        yield 'Stream' => [['success' => false, 'message' => 'Video not found.', 'statusCode' => 400], 'Video not found.', null, null];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('errorBodies')]
    public function testErrorDetailsAreKept(array $body, string $message, ?string $errorKey, ?string $field): void
    {
        $this->http->respondJson(400, $body);

        try {
            $this->api->send('POST', '/pullzone');
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status);
            self::assertSame($message, $e->getMessage());
            self::assertSame($errorKey, $e->errorKey);
            self::assertSame($field, $e->field);
            self::assertFalse($e->isRetryable());
        }
    }

    public function testNonJsonErrorBodyBecomesATruncatedMessage(): void
    {
        $this->http->respond(502, str_repeat('<html>Bad gateway</html>', 50));

        try {
            $this->api->send('GET', '/pullzone');
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(502, $e->status);
            self::assertSame(500, strlen($e->getMessage()));
            self::assertTrue($e->isRetryable());
        }
    }

    public function testEmptyErrorBodyGetsAGenericMessage(): void
    {
        $this->http->respond(401);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('bunny.net returned HTTP 401.');

        $this->api->send('GET', '/pullzone');
    }

    public function testRetryAfterInSeconds(): void
    {
        $this->http->respond(429, '', ['Retry-After' => '7']);

        self::assertSame(7.0, $this->rateLimited()->retryAfter);
    }

    public function testRetryAfterAsHttpDate(): void
    {
        $at = (new \DateTimeImmutable('@' . (int) $this->clock->now()))->modify('+30 seconds');
        $this->http->respond(429, '', ['Retry-After' => $at->format(\DATE_RFC7231)]);

        self::assertSame(30.0, $this->rateLimited()->retryAfter);
    }

    public function testRetryAfterMissing(): void
    {
        $this->http->respond(429);

        self::assertNull($this->rateLimited()->retryAfter);
    }

    private function rateLimited(): RateLimitedException
    {
        try {
            $this->api->send('POST', '/purge');
        } catch (RateLimitedException $e) {
            self::assertSame(429, $e->status);

            return $e;
        }
        self::fail('Expected a RateLimitedException.');
    }
}
