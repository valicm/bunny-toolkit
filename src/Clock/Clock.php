<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Clock;

/**
 * Time source for rate limiting and back-off, replaceable in tests.
 */
interface Clock
{
    /**
     * Current Unix time in seconds, with sub-second precision.
     */
    public function now(): float;

    public function sleep(float $seconds): void;
}
