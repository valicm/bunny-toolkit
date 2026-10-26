<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\CacheTag;

/**
 * Many tags per response, hashed and packed into one CDN-Tag value.
 *
 * Bunny caps CDN-Tag at 1024 bytes and, according to a third-party
 * integration, stores the whole value as a single tag instead of splitting
 * it. So each tag is hashed to a short token, the tokens are wrapped in
 * guards (": a1b2c3d4e5f6 0f9e8d7c6b5a :") and a tag is purged with a
 * wildcard match ("* a1b2c3d4e5f6 *"). The guards keep the outer spaces from
 * being trimmed as header whitespace. A hash collision only causes an extra
 * purge.
 *
 * Verify the single-value behaviour on a test pull zone before relying on it.
 *
 * @see https://packagist.org/packages/alengo/sulu-bunny-cdn-bundle
 */
final class HashedTagList implements TagFormat
{
    public function __construct(
        private readonly int $hashLength = 12,
        private readonly int $maxBytes = 1024,
        private readonly string $guard = ':',
    ) {
        if ($hashLength < 6 || $hashLength > 32) {
            throw new \InvalidArgumentException('Hash length must be between 6 and 32 characters.');
        }
        if ($guard === '' || preg_match('/[\s*]/', $guard) === 1) {
            throw new \InvalidArgumentException('The guard must be non-empty and contain no whitespace or "*".');
        }
        if ($maxBytes < $this->emptyLength() + $hashLength) {
            throw new \InvalidArgumentException('Max bytes is too small to hold a single tag.');
        }
    }

    public function header(iterable $tags): CdnTagHeader
    {
        $tokens = [];
        $included = [];
        $dropped = [];
        $length = $this->emptyLength();

        foreach ($tags as $tag) {
            if (isset($included[$tag]) || isset($dropped[$tag])) {
                continue;
            }

            $token = $this->token($tag);
            if (isset($tokens[$token])) {
                // Same token as an earlier tag: purging either reaches this response.
                $included[$tag] = true;
                continue;
            }

            $added = ($tokens === [] ? 0 : 1) + $this->hashLength;
            if ($length + $added > $this->maxBytes) {
                $dropped[$tag] = true;
                continue;
            }

            $tokens[$token] = true;
            $included[$tag] = true;
            $length += $added;
        }

        if ($tokens === []) {
            return new CdnTagHeader('', [], self::keys($dropped));
        }

        return new CdnTagHeader(
            $this->guard . ' ' . implode(' ', array_keys($tokens)) . ' ' . $this->guard,
            self::keys($included),
            self::keys($dropped),
        );
    }

    public function purgeValue(string $tag): string
    {
        return '* ' . $this->token($tag) . ' *';
    }

    /**
     * The hashed token written into the header for $tag.
     */
    public function token(string $tag): string
    {
        return substr(hash('xxh128', $tag), 0, $this->hashLength);
    }

    /**
     * Length of the guards and the spaces around the token list.
     */
    private function emptyLength(): int
    {
        return 2 * strlen($this->guard) + 2;
    }

    /**
     * @param array<array-key, true> $set
     * @return list<string>
     */
    private static function keys(array $set): array
    {
        return array_map(strval(...), array_keys($set));
    }
}
