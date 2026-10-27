<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Valicm\BunnyToolkit\Api\Core\CoreApi;
use Valicm\BunnyToolkit\Bunny;
use Valicm\BunnyToolkit\Clock\Clock;
use Valicm\BunnyToolkit\Http\ApiClient;

/**
 * Builds clients wired to a FakeHttpClient.
 */
final class TestApi
{
    /**
     * @param ClientInterface|null $httpClient Sends in place of $http, e.g. a FakeConcurrentClient around it.
     */
    public static function client(FakeHttpClient $http, string $baseUrl = CoreApi::BASE_URL, ?Clock $clock = null, ?ClientInterface $httpClient = null): ApiClient
    {
        $factory = new Psr17Factory();

        return new ApiClient($httpClient ?? $http, $factory, $factory, $baseUrl, 'secret-key', $clock ?? new FakeClock());
    }

    public static function bunny(FakeHttpClient $http, ?Clock $clock = null): Bunny
    {
        $factory = new Psr17Factory();

        return new Bunny('secret-key', $http, $factory, $factory, $clock ?? new FakeClock());
    }
}
