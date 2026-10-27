<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Http;

use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\RequestOptions;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle 7 as a PSR-18 client that also sends requests concurrently.
 *
 * Needs guzzlehttp/guzzle, which the toolkit only suggests. Pass it to Bunny
 * in place of the Guzzle client:
 *
 *     new Bunny($key, new GuzzleConcurrentClient(new Client()), $factory, $factory);
 *
 * The Guzzle client's own options, such as its timeout, apply to every
 * request.
 */
final class GuzzleConcurrentClient implements ClientInterface, ConcurrentClient
{
    public function __construct(
        private readonly GuzzleClientInterface&ClientInterface $client,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->client->sendRequest($request);
    }

    /**
     * @param array<array-key, RequestInterface> $requests
     *
     * @return array<array-key, ResponseInterface|ClientExceptionInterface>
     */
    public function sendConcurrently(array $requests): array
    {
        $outcomes = [];
        $failures = [];
        $promises = [];
        foreach ($requests as $key => $request) {
            // As sendRequest(): an error status is a response, not a rejection.
            $promises[] = $this->client->sendAsync($request, [RequestOptions::HTTP_ERRORS => false])->then(
                function (ResponseInterface $response) use (&$outcomes, $key): void {
                    $outcomes[$key] = $response;
                },
                function (mixed $reason) use (&$outcomes, &$failures, $key): void {
                    if ($reason instanceof ClientExceptionInterface) {
                        $outcomes[$key] = $reason;
                    } else {
                        $failures[] = $reason instanceof \Throwable ? $reason : new \UnexpectedValueException('A request was rejected without an exception.');
                    }
                },
            );
        }
        Utils::settle($promises)->wait();

        // Anything but a failed transfer, such as a bug in a middleware.
        if ($failures !== []) {
            throw $failures[0];
        }

        return $outcomes;
    }
}
