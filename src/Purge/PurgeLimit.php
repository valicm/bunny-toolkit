<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Purge;

/**
 * A token bucket: how many purges can go at once and how fast tokens return.
 *
 * Bunny support can raise an account's limits; pass the agreed values here.
 */
final readonly class PurgeLimit
{
    public function __construct(
        public int $burst,
        public float $refillPerSecond,
    ) {
        if ($burst < 1 || $refillPerSecond <= 0) {
            throw new \InvalidArgumentException('A purge limit needs a burst of at least 1 and a positive refill rate.');
        }
    }
}
