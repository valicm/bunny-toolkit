<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Purge\RateLimiter;

use Valicm\BunnyToolkit\Purge\PurgeType;

/**
 * Client-side copy of Bunny's purge limits, so purges wait instead of
 * collecting 429s.
 */
interface RateLimiter
{
    /**
     * Takes a token for $type if one is available.
     *
     * @return float 0.0 when a token was taken, otherwise the seconds until one will be.
     */
    public function tryConsume(PurgeType $type): float;

    /**
     * Records a 429 from Bunny: the next token for $type is available in $seconds.
     */
    public function backOff(PurgeType $type, float $seconds): void;
}
