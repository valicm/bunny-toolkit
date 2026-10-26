<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Purge\RateLimiter;

use Valicm\BunnyToolkit\Clock\Clock;
use Valicm\BunnyToolkit\Clock\SystemClock;
use Valicm\BunnyToolkit\Purge\PurgeLimit;
use Valicm\BunnyToolkit\Purge\PurgeType;

/**
 * Token buckets for Bunny's purge limits, held in memory.
 *
 * Bunny counts purges per account, so this only coordinates purges within
 * one PHP process. Workers on several hosts sharing an account need a
 * RateLimiter backed by shared storage, and should still expect 429s.
 */
final class InMemoryTokenBucket implements RateLimiter
{
    /**
     * Tokens left and when they were last counted, per bucket. A future
     * "updated" time means the bucket is blocked until then by a 429.
     *
     * @var array<string, array{tokens: float, updated: float}>
     */
    private array $buckets = [];

    private readonly PurgeLimit $exact;

    private readonly PurgeLimit $prefix;

    public function __construct(
        private readonly Clock $clock = new SystemClock(),
        ?PurgeLimit $exact = null,
        ?PurgeLimit $prefix = null,
    ) {
        $this->exact = $exact ?? PurgeType::Exact->defaultLimit();
        $this->prefix = $prefix ?? PurgeType::Prefix->defaultLimit();
    }

    public function tryConsume(PurgeType $type): float
    {
        $now = $this->clock->now();
        $limit = $this->limit($type);
        $bucket = $this->refill($type, $now);

        if ($bucket['updated'] > $now) {
            return $bucket['updated'] - $now;
        }
        if ($bucket['tokens'] >= 1.0) {
            $this->buckets[$type->name]['tokens'] -= 1.0;

            return 0.0;
        }

        return (1.0 - $bucket['tokens']) / $limit->refillPerSecond;
    }

    public function backOff(PurgeType $type, float $seconds): void
    {
        $now = $this->clock->now();
        $bucket = $this->refill($type, $now);

        // Bunny's Retry-After says when the next purge will be accepted, so
        // one token is ready at that moment and the rest refill from there.
        $this->buckets[$type->name] = [
            'tokens' => 1.0,
            'updated' => max($bucket['updated'], $now + max(0.0, $seconds)),
        ];
    }

    /**
     * @return array{tokens: float, updated: float}
     */
    private function refill(PurgeType $type, float $now): array
    {
        $limit = $this->limit($type);
        $bucket = $this->buckets[$type->name] ?? ['tokens' => (float) $limit->burst, 'updated' => $now];

        if ($now > $bucket['updated']) {
            $bucket['tokens'] = min((float) $limit->burst, $bucket['tokens'] + ($now - $bucket['updated']) * $limit->refillPerSecond);
            $bucket['updated'] = $now;
        }

        return $this->buckets[$type->name] = $bucket;
    }

    private function limit(PurgeType $type): PurgeLimit
    {
        return match ($type) {
            PurgeType::Exact => $this->exact,
            PurgeType::Prefix => $this->prefix,
        };
    }
}
