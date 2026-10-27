<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Tests\Unit\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Valicm\BunnyToolkit\Bunny;
use Valicm\BunnyToolkit\CacheTag\SingleTag;
use Valicm\BunnyToolkit\Http\GuzzleConcurrentClient;
use Valicm\BunnyToolkit\Tests\Support\FakeClock;

#[CoversClass(GuzzleConcurrentClient::class)]
final class GuzzleConcurrentClientTest extends TestCase
{
    public function testErrorStatusesAreResponsesAndFailedTransfersAreReturned(): void
    {
        $failed = new Request('POST', 'https://api.bunny.net/c');
        $client = $this->client([
            new Response(204),
            new Response(503),
            new ConnectException('Connection refused', $failed),
        ]);

        $outcomes = $client->sendConcurrently([
            'a' => new Request('POST', 'https://api.bunny.net/a'),
            'b' => new Request('POST', 'https://api.bunny.net/b'),
            'c' => $failed,
        ]);

        self::assertSame(['a', 'b', 'c'], array_keys($outcomes));
        self::assertInstanceOf(ResponseInterface::class, $outcomes['a']);
        self::assertSame(204, $outcomes['a']->getStatusCode());
        self::assertInstanceOf(ResponseInterface::class, $outcomes['b']);
        self::assertSame(503, $outcomes['b']->getStatusCode());
        self::assertInstanceOf(ConnectException::class, $outcomes['c']);
    }

    public function testSendRequestIsPsr18(): void
    {
        $response = $this->client([new Response(404)])->sendRequest(new Request('GET', 'https://api.bunny.net/x'));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testAPurgerSendsItsBatchConcurrently(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(204), new Response(204), new Response(204)]));
        $stack->push(Middleware::history($history));
        $factory = new HttpFactory();
        $bunny = new Bunny('secret-key', new GuzzleConcurrentClient(new Client(['handler' => $stack])), $factory, $factory, new FakeClock());

        $result = $bunny->purger(new SingleTag(), concurrency: 3)->purgeTags(42, ['a', 'b', 'c']);

        self::assertSame(['a', 'b', 'c'], $result->purged);
        $sent = [];
        self::assertIsArray($history);
        foreach ($history as $entry) {
            self::assertIsArray($entry);
            self::assertInstanceOf(RequestInterface::class, $entry['request']);
            $sent[] = (string) $entry['request']->getBody();
        }
        self::assertSame(['{"CacheTag":"a"}', '{"CacheTag":"b"}', '{"CacheTag":"c"}'], $sent);
    }

    /**
     * @param list<ResponseInterface|\Throwable> $queue
     */
    private function client(array $queue): GuzzleConcurrentClient
    {
        return new GuzzleConcurrentClient(new Client(['handler' => HandlerStack::create(new MockHandler($queue))]));
    }
}
