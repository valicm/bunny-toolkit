<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Purge;

/**
 * Outcome of a purge call.
 *
 * Deferred items are returned exactly as they were passed in, so a caller
 * can put them back on its queue unchanged.
 */
final readonly class PurgeResult
{
    /**
     * @param list<string> $purged Items Bunny accepted.
     * @param list<string> $deferred Items not sent, to retry later.
     * @param float|null $retryAfter Suggested delay in seconds before retrying, when known.
     * @param string|null $reason Why the remaining items were deferred.
     */
    public function __construct(
        public array $purged,
        public array $deferred = [],
        public ?float $retryAfter = null,
        public ?string $reason = null,
    ) {}

    public function isComplete(): bool
    {
        return $this->deferred === [];
    }
}
