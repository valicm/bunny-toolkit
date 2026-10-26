<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\CacheTag;

/**
 * A CDN-Tag response header value, and which tags made it in.
 */
final readonly class CdnTagHeader
{
    public const NAME = 'CDN-Tag';

    /**
     * @param string $value Header value; empty when there were no tags.
     * @param list<string> $included Tags a purge will reach.
     * @param list<string> $dropped Tags left out because the header was full; responses
     *   only stop carrying them when their cache TTL runs out.
     */
    public function __construct(
        public string $value,
        public array $included,
        public array $dropped = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->value === '';
    }
}
