<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\CacheTag;

/**
 * How tags are written into a CDN-Tag header, and the CacheTag value that
 * purges them. An origin and its purger must use the same format.
 */
interface TagFormat
{
    /**
     * @param iterable<string> $tags In priority order; tags that don't fit are dropped from the end.
     */
    public function header(iterable $tags): CdnTagHeader;

    /**
     * CacheTag value for the purge API that reaches every response tagged with $tag.
     */
    public function purgeValue(string $tag): string;
}
