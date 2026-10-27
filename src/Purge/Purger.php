<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Purge;

use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Valicm\BunnyToolkit\CacheTag\TagFormat;
use Valicm\BunnyToolkit\Clock\Clock;
use Valicm\BunnyToolkit\Clock\SystemClock;
use Valicm\BunnyToolkit\Http\ApiClient;
use Valicm\BunnyToolkit\Http\ApiException;
use Valicm\BunnyToolkit\Http\RateLimitedException;
use Valicm\BunnyToolkit\Purge\RateLimiter\RateLimiter;

/**
 * Purges bunny.net cache by tag, URL or whole pull zone, within Bunny's
 * purge rate limits.
 *
 * Each call spends at most $maxWait seconds waiting, whether for a rate-limit
 * token or for a 429's Retry-After. Anything it can't purge in that time comes
 * back in PurgeResult::$deferred for the caller to queue. 5xx responses and
 * network errors defer the rest the same way. Other API errors, such as a bad
 * key or an unknown pull zone, are thrown.
 *
 * Bunny takes one tag or URL per request. With $concurrency above 1 and a
 * ConcurrentClient as the HTTP client, up to that many are sent at once, each
 * holding a rate-limit token; otherwise they are sent one after another.
 *
 * Bunny answers 204 even when no cached file matched, so a purged item only
 * means Bunny accepted the request.
 */
final class Purger
{
    /**
     * @param ApiClient $client Core API transport, for api.bunny.net.
     * @param TagFormat $tagFormat Must match the format the origin writes into CDN-Tag.
     * @param float $maxWait Seconds a call may wait in total. Use 0 in web requests and more in queue workers.
     * @param int $concurrency Requests sent at once. Only takes effect with a ConcurrentClient.
     */
    public function __construct(
        private readonly ApiClient $client,
        private readonly TagFormat $tagFormat,
        private readonly RateLimiter $rateLimiter,
        private readonly float $maxWait = 0.0,
        private readonly Clock $clock = new SystemClock(),
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $concurrency = 1,
    ) {
        if ($concurrency < 1) {
            throw new \InvalidArgumentException(sprintf('Concurrency must be at least 1, %d given.', $concurrency));
        }
    }

    /**
     * Purges every response carrying any of the tags.
     *
     * Bunny's docs put purges by tag in the Exact bucket, without exception
     * for a wildcard CacheTag such as HashedTagList's, so every tag purge is
     * counted there. A 429 still backs off if Bunny counts them otherwise.
     *
     * @param iterable<string> $tags
     *
     * @throws \InvalidArgumentException When the tag format refuses a tag, before anything is sent.
     */
    public function purgeTags(int $pullZoneId, iterable $tags): PurgeResult
    {
        $path = $this->client->path('/pullzone/{id}/purgeCache', ['id' => $pullZoneId]);
        $requests = [];
        foreach (self::unique($tags) as $tag) {
            $requests[$tag] = [PurgeType::Exact, $this->client->createRequest('POST', $path, json: (object) ['CacheTag' => $this->tagFormat->purgeValue($tag)])];
        }

        return $this->run($requests);
    }

    /**
     * @param iterable<string> $urls Absolute URLs. A "*" makes it a prefix purge.
     * @param bool $async Return before Bunny has finished purging.
     */
    public function purgeUrls(iterable $urls, bool $async = false): PurgeResult
    {
        $requests = [];
        foreach (self::unique($urls) as $url) {
            $requests[$url] = [PurgeType::forValue($url), $this->client->createRequest('POST', '/purge', ['url' => $url, 'async' => $async])];
        }

        return $this->run($requests);
    }

    /**
     * Purges everything cached for the pull zone.
     */
    public function purgePullZone(int $pullZoneId): PurgeResult
    {
        // Without a body, Bunny purges the whole zone.
        return $this->run([
            (string) $pullZoneId => [PurgeType::Prefix, $this->client->createRequest('POST', $this->client->path('/pullzone/{id}/purgeCache', ['id' => $pullZoneId]))],
        ]);
    }

    /**
     * Sends the requests in order, a batch of up to $concurrency at a time,
     * each batch only as large as the rate limiter has tokens for.
     *
     * @param array<array-key, array{PurgeType, RequestInterface}> $requests Bucket and request, keyed by item.
     */
    private function run(array $requests): PurgeResult
    {
        $purged = [];
        $deadline = $this->clock->now() + $this->maxWait;
        /** @var list<string> $pending */
        $pending = array_map(strval(...), array_keys($requests));

        while ($pending !== []) {
            $batch = [];
            $wait = 0.0;
            foreach ($pending as $item) {
                if (count($batch) >= $this->concurrency) {
                    break;
                }
                $wait = $this->rateLimiter->tryConsume($requests[$item][0]);
                if ($wait > 0.0) {
                    break;
                }
                $batch[$item] = $requests[$item][1];
            }

            if ($batch === []) {
                if ($this->clock->now() + $wait > $deadline) {
                    return $this->defer($purged, $pending, $wait, sprintf('%s purge rate limit reached', $requests[$pending[0]][0]->name));
                }
                $this->clock->sleep($wait);
                continue;
            }

            $retry = [];
            $failed = null;
            $fatal = null;
            foreach ($this->client->sendAll($batch) as $item => $outcome) {
                $item = (string) $item;
                if ($outcome instanceof RateLimitedException) {
                    // Without Retry-After, wait for one token to refill.
                    $type = $requests[$item][0];
                    $this->rateLimiter->backOff($type, $outcome->retryAfter ?? 1.0 / $type->defaultLimit()->refillPerSecond);
                    $retry[] = $item;
                } elseif ($outcome instanceof ApiException && !$outcome->isRetryable()) {
                    $fatal ??= $outcome;
                } elseif ($outcome instanceof \Throwable) {
                    $failed ??= $outcome->getMessage();
                    $retry[] = $item;
                } else {
                    $purged[] = $item;
                }
            }

            if ($fatal !== null) {
                throw $fatal;
            }

            // What a 429 refused goes first, then what was never sent.
            $pending = [...$retry, ...array_slice($pending, count($batch))];

            if ($failed !== null) {
                return $this->defer($purged, $pending, null, $failed);
            }
        }

        return new PurgeResult($purged);
    }

    /**
     * @param list<string> $purged
     * @param list<string> $deferred
     */
    private function defer(array $purged, array $deferred, ?float $retryAfter, string $reason): PurgeResult
    {
        $this->logger->notice('Deferred {count} bunny.net purge item(s): {reason}', [
            'count' => count($deferred),
            'reason' => $reason,
        ]);

        return new PurgeResult($purged, $deferred, $retryAfter, $reason);
    }

    /**
     * @param iterable<string> $items
     * @return list<string>
     */
    private static function unique(iterable $items): array
    {
        $unique = [];
        foreach ($items as $item) {
            $unique[$item] = true;
        }

        return array_map(strval(...), array_keys($unique));
    }
}
