<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Caching;

/**
 * What a cached response may vary on at the edge.
 *
 * Not one switch: Bunny has a boolean per thing a response can differ by,
 * and they are separate decisions. A site serving WebP to browsers that
 * accept it wants one; a site with prices per country wants another.
 *
 * Each one multiplies the cache: a vary keeps a separate copy of every URL
 * per value, and two varies keep the product of both.
 */
enum VaryCache: string
{
    // WebP for browsers that accept it, the original for the rest. Two copies
    // per image URL, the cheapest vary there is.
    case Webp = 'webp';

    // The same for AVIF.
    case Avif = 'avif';

    // Mobile and desktop as separate copies, for a site that serves different
    // markup by device rather than the same markup responsively.
    case Mobile = 'mobile';

    // A copy per country.
    case Country = 'country';

    // A copy per region within a country, on top of the country.
    case CountryState = 'country_state';

    // A copy per hostname, where one zone serves domains that must not share
    // a cache.
    case Hostname = 'hostname';

    // A copy per value of the cookies CachingChoices::$varyCookies names.
    case Cookie = 'cookie';

    /**
     * The pull zone setting Bunny turns it on with.
     */
    public function property(): string
    {
        return match ($this) {
            self::Webp => 'EnableWebpVary',
            self::Avif => 'EnableAvifVary',
            self::Mobile => 'EnableMobileVary',
            self::Country => 'EnableCountryCodeVary',
            self::CountryState => 'EnableCountryStateCodeVary',
            self::Hostname => 'EnableHostnameVary',
            self::Cookie => 'EnableCookieVary',
        };
    }

    /**
     * What to call it where somebody is choosing.
     */
    public function label(): string
    {
        return match ($this) {
            self::Webp => 'WebP images',
            self::Avif => 'AVIF images',
            self::Mobile => 'Mobile and desktop',
            self::Country => 'Country',
            self::CountryState => 'Region within a country',
            self::Hostname => 'Hostname',
            self::Cookie => 'Cookie',
        };
    }

    /**
     * What choosing it costs, in the words somebody choosing needs.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Webp => 'Serve WebP where the browser accepts it. Two copies of each image.',
            self::Avif => 'The same for AVIF. Another copy of each image again.',
            self::Mobile => 'Only if you serve different markup to phones rather than the same markup responsively.',
            self::Country => 'A separate copy per country. For prices, languages and anything that differs by where somebody is.',
            self::CountryState => 'A copy per region as well as per country, which multiplies the one above rather than replacing it.',
            self::Hostname => 'A separate cache per domain.',
            self::Cookie => 'A copy per value of the cookies you name, and only those. Varying on every cookie would mean a copy per visitor, so the names are required.',
        };
    }

    /**
     * Every option, for a form that offers them.
     *
     * @return array<string, string> Label by value, in the order above.
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $vary) {
            $options[$vary->value] = $vary->label();
        }

        return $options;
    }

    /**
     * The ones these values stand for, ignoring anything unrecognised.
     *
     * @param iterable<mixed> $values
     * @return list<self>
     */
    public static function some(iterable $values): array
    {
        $found = [];
        foreach ($values as $value) {
            $vary = $value instanceof self ? $value : self::tryFrom(trim(is_scalar($value) ? (string) $value : ''));
            if ($vary !== null && !in_array($vary, $found, true)) {
                $found[] = $vary;
            }
        }

        return $found;
    }
}
