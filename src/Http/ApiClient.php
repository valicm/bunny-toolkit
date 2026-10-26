<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Valicm\BunnyToolkit\Clock\Clock;
use Valicm\BunnyToolkit\Clock\SystemClock;

/**
 * Sends requests to one bunny.net API base URL with its access key.
 *
 * The generated API classes call this. Error statuses are thrown as
 * ApiException with Bunny's error details, and 429s as RateLimitedException
 * with the Retry-After delay.
 */
final class ApiClient
{
    private const MAX_MESSAGE_LENGTH = 500;

    private readonly string $baseUrl;

    /**
     * @param string $baseUrl Scheme, host and optional path prefix, e.g. "https://api.bunny.net/mc".
     * @param string $accessKey Account API key, or the storage zone password / Stream library key for those APIs.
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        string $baseUrl,
        #[\SensitiveParameter]
        private readonly string $accessKey,
        private readonly Clock $clock = new SystemClock(),
        private readonly string $userAgent = 'valicm/bunny-toolkit',
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Fills "{name}" placeholders with URL-encoded values.
     *
     * @param array<string, int|string|float|bool|\BackedEnum|null> $params
     * @param list<string> $keepSlashes Parameters whose "/" separate path segments, such as a storage file path.
     */
    public function path(string $template, array $params, array $keepSlashes = []): string
    {
        $replacements = [];
        foreach ($params as $name => $value) {
            $value = self::scalar($value);
            $replacements['{' . $name . '}'] = in_array($name, $keepSlashes, true)
                ? implode('/', array_map(rawurlencode(...), explode('/', trim($value, '/'))))
                : rawurlencode($value);
        }

        return strtr($template, $replacements);
    }

    /**
     * Sends a request and decodes the JSON response; null when the body is empty.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $headers
     *
     * @throws ApiException
     * @throws UnexpectedResponseException When the body isn't valid JSON.
     * @throws ClientExceptionInterface
     */
    public function json(string $method, string $path, array $query = [], array $headers = [], mixed $json = null, StreamInterface|string|null $raw = null): mixed
    {
        $body = (string) $this->request($method, $path, $query, ['Accept' => 'application/json'] + $headers, $json, $raw)->getBody();
        if (trim($body) === '') {
            return null;
        }

        try {
            return json_decode($body, true, flags: \JSON_BIGINT_AS_STRING | \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new UnexpectedResponseException(sprintf('bunny.net returned invalid JSON for %s %s: %s', $method, $path, $e->getMessage()), 0, $e);
        }
    }

    /**
     * Sends a request and returns the body as text, e.g. a CSV export.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $headers
     *
     * @throws ApiException
     * @throws ClientExceptionInterface
     */
    public function text(string $method, string $path, array $query = [], array $headers = [], mixed $json = null, StreamInterface|string|null $raw = null): string
    {
        return (string) $this->request($method, $path, $query, $headers, $json, $raw)->getBody();
    }

    /**
     * Sends a request and returns the body unread, e.g. a file download.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $headers
     *
     * @throws ApiException
     * @throws ClientExceptionInterface
     */
    public function stream(string $method, string $path, array $query = [], array $headers = [], mixed $json = null, StreamInterface|string|null $raw = null): StreamInterface
    {
        return $this->request($method, $path, $query, $headers, $json, $raw)->getBody();
    }

    /**
     * Sends a request whose response has no useful body.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $headers
     *
     * @throws ApiException
     * @throws ClientExceptionInterface
     */
    public function send(string $method, string $path, array $query = [], array $headers = [], mixed $json = null, StreamInterface|string|null $raw = null): void
    {
        $this->request($method, $path, $query, $headers, $json, $raw);
    }

    /**
     * @param array<string, mixed> $query Null values are left out; lists repeat the key.
     * @param array<string, mixed> $headers Null values are left out.
     * @param mixed $json Body to send as JSON. Pass an object for a JSON object, so an empty one isn't sent as [].
     * @param StreamInterface|string|null $raw Body to send as-is, as application/octet-stream.
     *
     * @throws ApiException
     * @throws ClientExceptionInterface
     */
    public function request(string $method, string $path, array $query = [], array $headers = [], mixed $json = null, StreamInterface|string|null $raw = null): ResponseInterface
    {
        $url = $this->baseUrl . $path;
        $queryString = self::queryString($query);
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }

        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('AccessKey', $this->accessKey)
            ->withHeader('User-Agent', $this->userAgent);
        foreach ($headers as $name => $value) {
            if ($value !== null) {
                $request = $request->withHeader($name, self::scalar($value));
            }
        }

        if ($json !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(json_encode($json, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION)));
        } elseif ($raw !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/octet-stream')
                ->withBody(is_string($raw) ? $this->streamFactory->createStream($raw) : $raw);
        }

        $response = $this->httpClient->sendRequest($request);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw $this->toException($response);
        }

        return $response;
    }

    private function toException(ResponseInterface $response): ApiException
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $error = json_decode($body, true);
        $error = is_array($error) ? $error : [];

        // Core and Edge Scripting send {ErrorKey, Field, Message}, Shield and
        // Magic Containers RFC 7807 problem details, Stream {message}.
        $message = self::string($error, 'Message')
            ?? self::string($error, 'detail')
            ?? self::string($error, 'title')
            ?? self::string($error, 'message')
            ?? self::string($error, 'error')
            ?? (trim($body) !== '' ? substr(trim($body), 0, self::MAX_MESSAGE_LENGTH) : sprintf('bunny.net returned HTTP %d.', $status));
        $errorKey = self::string($error, 'ErrorKey');
        $field = self::string($error, 'Field');

        if ($status === 429) {
            return new RateLimitedException($message, $this->retryAfter($response), $errorKey, $field);
        }

        return new ApiException($message, $status, $errorKey, $field);
    }

    /**
     * Seconds from the Retry-After header, in either of its formats.
     */
    private function retryAfter(ResponseInterface $response): ?float
    {
        $header = trim($response->getHeaderLine('Retry-After'));
        if ($header === '') {
            return null;
        }
        if (is_numeric($header)) {
            return max(0.0, (float) $header);
        }

        $date = \DateTimeImmutable::createFromFormat(\DATE_RFC7231, $header);

        return $date === false ? null : max(0.0, $date->getTimestamp() - $this->clock->now());
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function queryString(array $query): string
    {
        $parts = [];
        foreach ($query as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if ($item !== null) {
                    $parts[] = rawurlencode($name) . '=' . rawurlencode(self::scalar($item));
                }
            }
        }

        return implode('&', $parts);
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \DateTimeInterface => $value->format(\DATE_ATOM),
            is_int($value), is_float($value), is_string($value) => (string) $value,
            $value === null => '',
            default => throw new \InvalidArgumentException(sprintf('Cannot send a %s as a query, header or path value.', get_debug_type($value))),
        };
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
