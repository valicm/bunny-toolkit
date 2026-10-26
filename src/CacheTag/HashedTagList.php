<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\CacheTag;

/**
 * Many tags per response, hashed and packed into one CDN-Tag value.
 *
 * Bunny caps CDN-Tag at 1024 bytes and, according to a third-party
 * integration, stores the whole value as a single tag instead of splitting
 * it. So each tag is hashed to a short token, the tokens are wrapped in
 * guards (": k3XqA 7m-2d :") and a tag is purged with a wildcard match
 * ("* k3XqA *"). The guards keep the outer spaces from being trimmed as
 * header whitespace. A hash collision only causes an extra purge.
 *
 * Tokens are URL-safe base64. Shorter tokens fit more tags but purge more
 * unrelated pages:
 *
 * | Length | Bits | Tags in 1024 bytes | A purge also hits a full page   |
 * |--------|------|--------------------|---------------------------------|
 * | 3      | 18   | 255                | 1 in 1,000                      |
 * | 4      | 24   | 204                | 1 in 82,000                     |
 * | 5      | 30   | 170                | 1 in 6.3 million                |
 * | 6      | 36   | 145                | 1 in 470 million                |
 * | 7      | 42   | 127                | 1 in 35 billion                 |
 * | 8      | 48   | 113                | 1 in 2.5 trillion               |
 *
 * The default is 5.
 *
 * Verify the single-value behaviour on a test pull zone before relying on it.
 *
 * @see https://packagist.org/packages/alengo/sulu-bunny-cdn-bundle
 */
final class HashedTagList implements TagFormat
{
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

    public const int MIN_HASH_LENGTH = 3;

    public const int MAX_HASH_LENGTH = 8;

    public function __construct(
        private readonly int $hashLength = 5,
        private readonly int $maxBytes = 1024,
        private readonly string $guard = ':',
    ) {
        if ($hashLength < self::MIN_HASH_LENGTH || $hashLength > self::MAX_HASH_LENGTH) {
            throw new \InvalidArgumentException(sprintf('Hash length must be between %d and %d characters.', self::MIN_HASH_LENGTH, self::MAX_HASH_LENGTH));
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
        $bits = '';
        foreach (str_split(hash('xxh128', $tag)) as $hex) {
            $bits .= str_pad(base_convert($hex, 16, 2), 4, '0', STR_PAD_LEFT);
        }
        $token = '';
        foreach (str_split(substr($bits, 0, $this->hashLength * 6), 6) as $chunk) {
            $token .= self::ALPHABET[(int) bindec($chunk)];
        }

        return $token;
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
