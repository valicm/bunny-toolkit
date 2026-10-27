<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Purge;

/**
 * Bunny's purge rate-limit buckets.
 *
 * Limits apply per account, not per pull zone, so every project on the same
 * account shares them. Each purged item takes one token.
 *
 * @see https://bunny.net/docs/cdn/purge-cache
 */
enum PurgeType
{
    /**
     * Exact URL and cache-tag purges.
     */
    case Exact;

    /**
     * Wildcard URL purges and full pull-zone purges.
     */
    case Prefix;

    /**
     * The bucket a purge URL draws from.
     *
     * Not for cache tags: Bunny puts every purge by tag in the Exact bucket,
     * a wildcard CacheTag included.
     */
    public static function forValue(string $value): self
    {
        // Bunny treats a URL containing "*" as a prefix purge.
        return str_contains($value, '*') ? self::Prefix : self::Exact;
    }

    /**
     * Bunny's default limit for this bucket.
     */
    public function defaultLimit(): PurgeLimit
    {
        return match ($this) {
            self::Exact => new PurgeLimit(burst: 120, refillPerSecond: 5.0),
            self::Prefix => new PurgeLimit(burst: 20, refillPerSecond: 0.5),
        };
    }
}
