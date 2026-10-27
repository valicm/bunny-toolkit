<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Valicm\BunnyToolkit\Http\ConcurrentClient;

/**
 * ConcurrentClient that answers from a FakeHttpClient's queue and records
 * how many requests each concurrent call carried.
 */
final class FakeConcurrentClient implements ClientInterface, ConcurrentClient
{
    /**
     * @var list<int>
     */
    public array $batches = [];

    public function __construct(
        public readonly FakeHttpClient $http = new FakeHttpClient(),
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->batches[] = 1;

        return $this->http->sendRequest($request);
    }

    public function sendConcurrently(array $requests): array
    {
        $this->batches[] = count($requests);
        $outcomes = [];
        foreach ($requests as $key => $request) {
            try {
                $outcomes[$key] = $this->http->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                $outcomes[$key] = $e;
            }
        }

        return $outcomes;
    }
}
