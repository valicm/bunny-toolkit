<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client that answers from a queue and records every request.
 */
final class FakeHttpClient implements ClientInterface
{
    /**
     * @var list<RequestInterface>
     */
    public array $requests = [];

    /**
     * Responses to return in order; a string is a network failure message.
     *
     * @var list<ResponseInterface|string>
     */
    private array $queue = [];

    /**
     * @param array<string, string> $headers
     */
    public function respond(int $status, string $body = '', array $headers = []): self
    {
        $this->queue[] = new Response($status, $headers, $body);

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function respondJson(int $status, array $data): self
    {
        return $this->respond($status, json_encode($data, \JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json']);
    }

    public function failWithNetworkError(string $message = 'Connection refused'): self
    {
        $this->queue[] = $message;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException(sprintf('Unexpected request: %s %s', $request->getMethod(), $request->getUri()));
        }
        if (is_string($next)) {
            throw new FakeNetworkException($next, $request);
        }

        return $next;
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function jsonBody(RequestInterface $request): array
    {
        $body = json_decode((string) $request->getBody(), true, flags: \JSON_THROW_ON_ERROR);

        return is_array($body) ? $body : [];
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function query(RequestInterface $request): array
    {
        parse_str($request->getUri()->getQuery(), $query);

        return $query;
    }
}
