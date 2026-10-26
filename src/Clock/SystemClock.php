<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Clock;

final class SystemClock implements Clock
{
    public function now(): float
    {
        return microtime(true);
    }

    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) ceil($seconds * 1_000_000));
        }
    }
}
