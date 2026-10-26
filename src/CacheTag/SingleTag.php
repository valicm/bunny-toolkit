<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\CacheTag;

/**
 * One plain tag per response, purged by exact match.
 *
 * For responses that depend on one thing, such as an endpoint serving a
 * single lot. Exact tag purges use Bunny's larger rate-limit bucket. Extra
 * tags passed to header() are reported as dropped.
 */
final class SingleTag implements TagFormat
{
    private const MAX_BYTES = 1024;

    public function header(iterable $tags): CdnTagHeader
    {
        $tag = null;
        $dropped = [];

        foreach ($tags as $candidate) {
            if ($tag === null) {
                $tag = $this->validate($candidate);
            } elseif ($candidate !== $tag && !in_array($candidate, $dropped, true)) {
                $dropped[] = $candidate;
            }
        }

        return $tag === null
            ? new CdnTagHeader('', [])
            : new CdnTagHeader($tag, [$tag], $dropped);
    }

    public function purgeValue(string $tag): string
    {
        return $this->validate($tag);
    }

    private function validate(string $tag): string
    {
        // Printable ASCII without spaces, and no "*" so the value can't act as a wildcard.
        if ($tag === '' || strlen($tag) > self::MAX_BYTES || preg_match('/[^!-~]|\*/', $tag) === 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid single cache tag: use 1-%d printable ASCII characters without spaces or "*".', $tag, self::MAX_BYTES));
        }

        return $tag;
    }
}
