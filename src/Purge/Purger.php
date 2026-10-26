<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Purge;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Valicm\BunnyToolkit\Api\Core\CoreApi;
use Valicm\BunnyToolkit\CacheTag\TagFormat;
use Valicm\BunnyToolkit\Clock\Clock;
use Valicm\BunnyToolkit\Clock\SystemClock;
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
 * Bunny answers 204 even when no cached file matched, so a purged item only
 * means Bunny accepted the request.
 */
final class Purger
{
    /**
     * @param TagFormat $tagFormat Must match the format the origin writes into CDN-Tag.
     * @param float $maxWait Seconds a call may wait in total. Use 0 in web requests and more in queue workers.
     */
    public function __construct(
        private readonly CoreApi $core,
        private readonly TagFormat $tagFormat,
        private readonly RateLimiter $rateLimiter,
        private readonly float $maxWait = 0.0,
        private readonly Clock $clock = new SystemClock(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @param iterable<string> $tags
     */
    public function purgeTags(int $pullZoneId, iterable $tags): PurgeResult
    {
        return $this->run(self::unique($tags), function (string $tag) use ($pullZoneId): array {
            $value = $this->tagFormat->purgeValue($tag);

            return [PurgeType::forValue($value), fn() => $this->core->pullZone()->purgeCache($pullZoneId, ['CacheTag' => $value])];
        });
    }

    /**
     * @param iterable<string> $urls Absolute URLs. A "*" makes it a prefix purge.
     * @param bool $async Return before Bunny has finished purging.
     */
    public function purgeUrls(iterable $urls, bool $async = false): PurgeResult
    {
        return $this->run(self::unique($urls), fn(string $url): array => [
            PurgeType::forValue($url),
            fn() => $this->core->purge()->purgeUrl($url, $async),
        ]);
    }

    /**
     * Purges everything cached for the pull zone.
     */
    public function purgePullZone(int $pullZoneId): PurgeResult
    {
        // Without a body, Bunny purges the whole zone.
        return $this->run([(string) $pullZoneId], fn(): array => [
            PurgeType::Prefix,
            fn() => $this->core->pullZone()->purgeCache($pullZoneId),
        ]);
    }

    /**
     * @param list<string> $items
     * @param \Closure(string): array{PurgeType, \Closure(): void} $request Bucket and API call for one item.
     */
    private function run(array $items, \Closure $request): PurgeResult
    {
        $purged = [];
        $deadline = $this->clock->now() + $this->maxWait;

        foreach ($items as $index => $item) {
            [$type, $send] = $request($item);

            while (true) {
                $wait = $this->rateLimiter->tryConsume($type);
                if ($wait > 0.0) {
                    if ($this->clock->now() + $wait > $deadline) {
                        return $this->defer($purged, array_slice($items, $index), $wait, sprintf('%s purge rate limit reached', $type->name));
                    }
                    $this->clock->sleep($wait);
                    continue;
                }

                try {
                    $send();
                    $purged[] = $item;
                    break;
                } catch (RateLimitedException $e) {
                    // Without Retry-After, wait for one token to refill.
                    $this->rateLimiter->backOff($type, $e->retryAfter ?? 1.0 / $type->defaultLimit()->refillPerSecond);
                } catch (ApiException $e) {
                    if (!$e->isRetryable()) {
                        throw $e;
                    }

                    return $this->defer($purged, array_slice($items, $index), null, $e->getMessage());
                } catch (ClientExceptionInterface $e) {
                    return $this->defer($purged, array_slice($items, $index), null, $e->getMessage());
                }
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
