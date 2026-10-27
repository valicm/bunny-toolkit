<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * An HTTP client that can send several requests at once.
 *
 * PSR-18 sends one request at a time, and Bunny purges one tag or URL per
 * request, so a long purge is as many round trips. Pass a client that also
 * implements this, such as GuzzleConcurrentClient, and ApiClient::sendAll()
 * sends them together.
 */
interface ConcurrentClient
{
    /**
     * Sends every request and waits for all of them.
     *
     * An error status is a response, as in PSR-18; only a request that got
     * no response is an exception, returned in its place rather than thrown.
     *
     * @param array<array-key, RequestInterface> $requests
     *
     * @return array<array-key, ResponseInterface|ClientExceptionInterface> Keyed as given.
     */
    public function sendConcurrently(array $requests): array;
}
