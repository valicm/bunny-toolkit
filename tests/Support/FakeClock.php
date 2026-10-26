<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Tests\Support;

use Valicm\BunnyToolkit\Clock\Clock;

/**
 * Clock that only moves when told to, or when something sleeps.
 */
final class FakeClock implements Clock
{
    /**
     * @var list<float>
     */
    public array $sleeps = [];

    public function __construct(
        private float $now = 1_700_000_000.0,
    ) {}

    public function now(): float
    {
        return $this->now;
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->advance($seconds);
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}
