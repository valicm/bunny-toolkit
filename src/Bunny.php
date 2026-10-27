<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Valicm\BunnyToolkit\Api\Core\CoreApi;
use Valicm\BunnyToolkit\Api\Database\DatabaseApi;
use Valicm\BunnyToolkit\Api\EdgeScripting\EdgeScriptingApi;
use Valicm\BunnyToolkit\Api\Logging\LoggingApi;
use Valicm\BunnyToolkit\Api\MagicContainers\MagicContainersApi;
use Valicm\BunnyToolkit\Api\OriginErrors\OriginErrorsApi;
use Valicm\BunnyToolkit\Api\Partner\PartnerApi;
use Valicm\BunnyToolkit\Api\Shield\ShieldApi;
use Valicm\BunnyToolkit\Api\Storage\StorageApi;
use Valicm\BunnyToolkit\Api\Stream\StreamApi;
use Valicm\BunnyToolkit\CacheTag\HashedTagList;
use Valicm\BunnyToolkit\CacheTag\TagFormat;
use Valicm\BunnyToolkit\Clock\Clock;
use Valicm\BunnyToolkit\Clock\SystemClock;
use Valicm\BunnyToolkit\EdgeScript\EdgeScriptDeployer;
use Valicm\BunnyToolkit\Http\ApiClient;
use Valicm\BunnyToolkit\Purge\Purger;
use Valicm\BunnyToolkit\Purge\RateLimiter\InMemoryTokenBucket;
use Valicm\BunnyToolkit\Purge\RateLimiter\RateLimiter;

/**
 * Entry point: one object per bunny.net API, plus the toolkit services.
 *
 * Purgers made here share one in-memory rate limiter unless given another.
 */
final class Bunny
{
    private ?RateLimiter $rateLimiter = null;

    /**
     * @param string $apiKey Account API key.
     * @param ClientInterface $httpClient Any PSR-18 client, e.g. Guzzle 7.
     * @param RequestFactoryInterface $requestFactory PSR-17 factory, e.g. GuzzleHttp\Psr7\HttpFactory.
     * @param StreamFactoryInterface $streamFactory PSR-17 factory, often the same object.
     */
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $apiKey,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly Clock $clock = new SystemClock(),
    ) {}

    /**
     * Pull zones, edge rules, purge, DNS, storage zones, billing, statistics.
     */
    public function core(): CoreApi
    {
        return new CoreApi($this->client(CoreApi::BASE_URL, $this->apiKey));
    }

    public function edgeScripting(): EdgeScriptingApi
    {
        return new EdgeScriptingApi($this->client(EdgeScriptingApi::BASE_URL, $this->apiKey));
    }

    public function shield(): ShieldApi
    {
        return new ShieldApi($this->client(ShieldApi::BASE_URL, $this->apiKey));
    }

    public function magicContainers(): MagicContainersApi
    {
        return new MagicContainersApi($this->client(MagicContainersApi::BASE_URL, $this->apiKey));
    }

    public function database(): DatabaseApi
    {
        return new DatabaseApi($this->client(DatabaseApi::BASE_URL, $this->apiKey));
    }

    /**
     * Pull zones' CDN access logs, from logging.bunnycdn.com.
     */
    public function logging(): LoggingApi
    {
        return new LoggingApi($this->client(LoggingApi::BASE_URL, $this->apiKey));
    }

    public function originErrors(): OriginErrorsApi
    {
        return new OriginErrorsApi($this->client(OriginErrorsApi::BASE_URL, $this->apiKey));
    }

    /**
     * Customers, pricing, products and billing, for a partner account's key.
     */
    public function partner(): PartnerApi
    {
        return new PartnerApi($this->client(PartnerApi::BASE_URL, $this->apiKey));
    }

    /**
     * Edge Storage authenticates with the storage zone's password, not the account key.
     */
    public function storage(
        #[\SensitiveParameter]
        string $zonePassword,
        StorageRegion $region = StorageRegion::Falkenstein,
    ): StorageApi {
        return new StorageApi($this->client($region->value, $zonePassword));
    }

    /**
     * Stream authenticates with the video library's API key, not the account key.
     */
    public function stream(
        #[\SensitiveParameter]
        string $libraryApiKey,
    ): StreamApi {
        return new StreamApi($this->client(StreamApi::BASE_URL, $libraryApiKey));
    }

    /**
     * @param float $maxWait Seconds each purge call may wait; see Purger.
     * @param int $concurrency Purge requests sent at once, when the HTTP client is a Http\ConcurrentClient.
     */
    public function purger(
        TagFormat $tagFormat = new HashedTagList(),
        ?RateLimiter $rateLimiter = null,
        float $maxWait = 0.0,
        LoggerInterface $logger = new NullLogger(),
        int $concurrency = 1,
    ): Purger {
        $rateLimiter ??= $this->rateLimiter ??= new InMemoryTokenBucket($this->clock);

        return new Purger($this->client(CoreApi::BASE_URL, $this->apiKey), $tagFormat, $rateLimiter, $maxWait, $this->clock, $logger, $concurrency);
    }

    public function edgeScriptDeployer(): EdgeScriptDeployer
    {
        return new EdgeScriptDeployer($this->edgeScripting());
    }

    private function client(string $baseUrl, string $accessKey): ApiClient
    {
        return new ApiClient($this->httpClient, $this->requestFactory, $this->streamFactory, $baseUrl, $accessKey, $this->clock);
    }
}
