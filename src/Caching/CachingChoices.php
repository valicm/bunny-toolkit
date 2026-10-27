<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Caching;

/**
 * What somebody chose about a pull zone's caching.
 *
 * ZoneCaching turns these into the zone's settings and Edge Rules, and reads
 * them back from a zone. Lists are kept as given; ZoneCaching cleans them
 * with the methods here before sending, and drops what Bunny would refuse or
 * what would turn the cache off for everybody.
 */
final readonly class CachingChoices
{
    /**
     * How many request headers the cache may vary on.
     */
    public const MAX_HEADERS = 10;

    /**
     * How many cookies the cache may vary on.
     */
    public const MAX_COOKIES = 10;

    /**
     * How many session cookies may be named besides the framework's.
     *
     * Each is two patterns, and the page browser rule carries every session
     * cookie in the three triggers it has left after content type and
     * privacy: fifteen patterns, four of them a framework's.
     */
    public const MAX_SESSION_COOKIES = 5;

    /**
     * How many paths may skip the cache: four triggers of five patterns.
     */
    public const MAX_SKIP_PATHS = 20;

    /**
     * Browser times for a page, in seconds.
     *
     * Short, because Bunny drops the `Vary: Cookie` a page is sent with: a
     * page a visitor saw signed out stays in their browser, signed out, for
     * this long after they sign in.
     */
    public const PAGE_TIMES = [300, 900, 3600, 86400];

    /**
     * Browser times for a static file, in seconds.
     *
     * Long, because a file is the same for everybody, and a changed one gets
     * a new URL from any framework that builds them.
     */
    public const FILE_TIMES = [86400, 604800, 2592000];

    /**
     * Headers refused as a vary, lower-cased, with what to do instead.
     *
     * Each would be a copy per visitor rather than per kind of visitor.
     */
    private const REFUSED_HEADERS = [
        'cookie' => 'vary on the cookies that matter by name instead',
        'user-agent' => 'every browser version would get its own copy; use the mobile and desktop vary',
    ];

    /**
     * @param bool $dynamic Bunny's Smart Cache: Bunny decides what is cacheable.
     * @param list<VaryCache> $vary What the cache varies on.
     * @param list<string> $varyHeaders Request headers the cache varies on.
     * @param list<string> $varyCookies Cookies the cookie vary is keyed on.
     * @param list<string> $blockedCountries ISO 3166-1 alpha-2 codes answered with a 403.
     * @param list<string> $skipPaths Paths never cached, such as `/admin/*`.
     * @param list<string> $sessionCookies Session cookies besides the framework's, or the start of their names.
     * @param int|null $browserPages One of PAGE_TIMES, or null to leave it to the origin.
     * @param int|null $browserFiles One of FILE_TIMES, or null to leave it to the origin.
     */
    public function __construct(
        public bool $dynamic = false,
        public array $vary = [],
        public array $varyHeaders = [],
        public array $varyCookies = [],
        public array $blockedCountries = [],
        public array $skipPaths = [],
        public array $sessionCookies = [],
        public ?int $browserPages = null,
        public ?int $browserFiles = null,
    ) {}

    /**
     * @param list<string> $paths
     */
    public function withSkipPaths(array $paths): self
    {
        return new self($this->dynamic, $this->vary, $this->varyHeaders, $this->varyCookies, $this->blockedCountries, $paths, $this->sessionCookies, $this->browserPages, $this->browserFiles);
    }

    /**
     * @param list<string> $names
     */
    public function withSessionCookies(array $names): self
    {
        return new self($this->dynamic, $this->vary, $this->varyHeaders, $this->varyCookies, $this->blockedCountries, $this->skipPaths, $names, $this->browserPages, $this->browserFiles);
    }

    public function withBrowserCache(?int $pages, ?int $files): self
    {
        return new self($this->dynamic, $this->vary, $this->varyHeaders, $this->varyCookies, $this->blockedCountries, $this->skipPaths, $this->sessionCookies, $pages, $files);
    }

    /**
     * Why a request header can't be varied on, or null when it can.
     */
    public static function headerProblem(string $header): ?string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', $header) !== 1) {
            return 'a header name is letters, digits and dashes';
        }

        return self::REFUSED_HEADERS[strtolower($header)] ?? null;
    }

    /**
     * Why a cookie can't be varied on, or null when it can.
     *
     * Bunny's own rule for the name, and a session cookie is refused: a copy
     * per session is a copy per visitor.
     */
    public static function cookieProblem(string $cookie): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $cookie) !== 1) {
            return 'a cookie name here is letters, digits, dashes and underscores';
        }
        if (preg_match('/^(S?SESS[0-9a-f]+|PHPSESSID|laravel_session|wordpress_logged_in_.*|sessionid|session)$/i', $cookie) === 1) {
            return 'it is a session cookie, which would mean a copy per visitor';
        }

        return null;
    }

    /**
     * Trimmed, non-empty, and each once, ignoring case.
     *
     * A header name has no case; a cookie typed twice in different case is a
     * slip far more often than two cookies.
     *
     * @param iterable<mixed> $names
     * @return list<string>
     */
    public static function names(iterable $names): array
    {
        $kept = [];
        foreach ($names as $name) {
            $name = trim(is_scalar($name) ? (string) $name : '');
            if ($name !== '' && !isset($kept[strtolower($name)])) {
                $kept[strtolower($name)] = $name;
            }
        }

        return array_values($kept);
    }

    /**
     * Paths that may skip the cache: cleaned, deduplicated, bounded.
     *
     * A path starts with a slash and may carry `*`. Anything else is dropped:
     * a stray `*` on its own would turn the cache off for the whole site.
     *
     * @param iterable<mixed> $paths
     * @return list<string>
     */
    public static function normalisePaths(iterable $paths): array
    {
        $kept = [];
        foreach ($paths as $path) {
            $path = trim(is_scalar($path) ? (string) $path : '');
            if ($path === '/' || $path === '/*' || preg_match('#^/[A-Za-z0-9._~!$&\'()+,;=:@%/*-]*$#', $path) !== 1) {
                continue;
            }
            $kept[$path] = $path;
        }

        return array_slice(array_values($kept), 0, self::MAX_SKIP_PATHS);
    }

    /**
     * Session cookie names: plain names, deduplicated, bounded.
     *
     * Names, not patterns. A `*`, a space or a `;` is dropped: whoever carries
     * the name goes past the cache, and a stray `*` would send everybody.
     * Matched as the start of a name, so a cookie with a varying suffix is
     * named by the part that does not vary.
     *
     * @param iterable<mixed> $names
     * @return list<string>
     */
    public static function normaliseSessionCookies(iterable $names): array
    {
        $kept = [];
        foreach ($names as $name) {
            $name = trim(is_scalar($name) ? (string) $name : '');
            if (preg_match('/^[A-Za-z0-9._-]+$/', $name) === 1) {
                $kept[$name] = $name;
            }
        }

        return array_slice(array_values($kept), 0, self::MAX_SESSION_COOKIES);
    }

    /**
     * Country codes as Bunny takes them: two capitals, each once.
     *
     * The list is a real control, so a value that is not a country code is
     * dropped rather than sent for Bunny to interpret.
     *
     * @param iterable<mixed> $codes
     * @return list<string>
     */
    public static function normaliseCountries(iterable $codes): array
    {
        $kept = [];
        foreach ($codes as $code) {
            $code = strtoupper(trim(is_scalar($code) ? (string) $code : ''));
            if (preg_match('/^[A-Z]{2}$/', $code) === 1) {
                $kept[$code] = $code;
            }
        }

        return array_values($kept);
    }
}
