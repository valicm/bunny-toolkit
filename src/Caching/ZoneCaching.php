<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Caching;

use Valicm\BunnyToolkit\Api\Core\Enum\EdgeRuleActionType as Action;
use Valicm\BunnyToolkit\Api\Core\Enum\PatternMatchingTypes as Pattern;
use Valicm\BunnyToolkit\Api\Core\Enum\TriggerMatchingTypes as Matching;
use Valicm\BunnyToolkit\Api\Core\Enum\TriggerTypes as Trigger;

/**
 * Caching choices as a pull zone's settings and Edge Rules, and back.
 *
 * Nothing is sent from here: properties() is the body of an update pull zone
 * call, plan() the Edge Rule calls, which EdgeRulePlan::apply() can send.
 * read() turns a zone back into choices, so the zone itself can be the only
 * record of them.
 *
 * ## The rules
 *
 * Each is found again by its description, the prefix given here followed by
 * one of the constants below. Rules with other descriptions are left alone,
 * so rules made by hand or by other code live beside these.
 *
 * - ORIGIN_PRIVATE (20): a response the origin marks `private`, `no-cache` or
 *   `no-store` is never cached, and the browser is told so.
 * - BROWSER_PAGES (30), BROWSER_FILES (31): how long browsers keep pages and
 *   static files, never for a private response, and for pages never for a
 *   visitor with a session.
 * - SIGNED_IN (40), SESSION_COOKIES (41, 42): a request carrying the
 *   framework's session cookie, or one named in the choices, goes to the
 *   origin rather than the cache, except for static files.
 * - SKIP_PATHS (45): paths never cached at the edge or in the browser.
 *
 * Those OrderIndex values are taken: Bunny refuses two rules at one.
 *
 * ## Bunny's limits
 *
 * At most five patterns in a trigger and five triggers in a rule. Longer
 * lists are split across triggers, which is only the same thing for a
 * trigger matching *any* pattern in a rule matching *any* trigger, or *none*
 * in one matching *all*; every rule here is one of those two shapes.
 *
 * @phpstan-import-type EdgeRuleV2Model from \Valicm\BunnyToolkit\Api\Core\Types
 * @phpstan-import-type EdgeRuleV2ActionModel from \Valicm\BunnyToolkit\Api\Core\Types
 * @phpstan-import-type Trigger from \Valicm\BunnyToolkit\Api\Core\Types as TriggerModel
 */
final readonly class ZoneCaching
{
    public const ORIGIN_PRIVATE = 'never cache what the origin marks private';

    public const BROWSER_PAGES = 'browser cache time for pages';

    public const BROWSER_FILES = 'browser cache time for static files';

    public const SIGNED_IN = 'signed-in visitors skip the cache';

    public const SESSION_COOKIES = 'your session cookies skip the cache';

    /**
     * The rest of the named session cookies, past the first rule's five
     * patterns: a session rule matches a cookie *and* a URL that is not a
     * static file, so its cookies have to fit one trigger.
     */
    public const SESSION_COOKIES_MORE = 'your session cookies skip the cache (2)';

    public const SKIP_PATHS = 'paths never cached';

    /**
     * The most patterns Bunny takes in one trigger.
     */
    public const MAX_PATTERNS = 5;

    /**
     * Each rule's place.
     */
    private const ORDER = [
        self::ORIGIN_PRIVATE => 20,
        self::BROWSER_PAGES => 30,
        self::BROWSER_FILES => 31,
        self::SIGNED_IN => 40,
        self::SESSION_COOKIES => 41,
        self::SESSION_COOKIES_MORE => 42,
        self::SKIP_PATHS => 45,
    ];

    /**
     * A static file's URL ending, which a session does not keep uncached.
     *
     * Matched on the path alone; Bunny ignores the query string, so
     * `style.css?v=3` is a stylesheet. Nineteen, for the four triggers a rule
     * has left after its cookies.
     */
    private const STATIC_FILES = [
        '*.css', '*.js', '*.mjs', '*.map',
        '*.png', '*.jpg', '*.jpeg', '*.gif', '*.webp', '*.avif', '*.svg', '*.ico',
        '*.woff', '*.woff2', '*.ttf', '*.otf', '*.eot',
        '*.mp4', '*.webm',
    ];

    /**
     * What a page is, by the `Content-Type` the origin sent.
     *
     * HTML, and what an API answers, which is a page by every measure that
     * matters here: JSON, `+json` (JSON:API, HAL), XML (sitemaps) and `+xml`
     * (RSS, Atom), but not `image/svg+xml`. Five, as one trigger takes.
     */
    private const PAGE_TYPES = [
        '*text/html*',
        'application/json*',
        'application/*+json*',
        '*/xml*',
        'application/*+xml*',
    ];

    /**
     * What is not a static file: pages, plain text, CSV and event streams.
     *
     * Said this way round, so a type nobody predicted gets the file time.
     */
    private const NOT_FILE_TYPES = [
        ...self::PAGE_TYPES,
        'text/plain*',
        'text/csv*',
        'text/event-stream*',
    ];

    /**
     * What the origin says on a response that belongs to somebody.
     */
    private const PRIVATE_CACHE_CONTROL = ['*private*', '*no-cache*', '*no-store*'];

    /**
     * @param string $prefix Starts each rule's description, so they are found again.
     */
    public function __construct(
        private string $prefix = 'bunny-toolkit: ',
    ) {}

    /**
     * The description a rule is found by.
     *
     * @param self::ORIGIN_PRIVATE|self::BROWSER_PAGES|self::BROWSER_FILES|self::SIGNED_IN|self::SESSION_COOKIES|self::SESSION_COOKIES_MORE|self::SKIP_PATHS $rule
     */
    public function description(string $rule): string
    {
        return $this->prefix . $rule;
    }

    /**
     * Every rule this writes, by description.
     *
     * @return list<string>
     */
    public function owned(): array
    {
        return array_map($this->description(...), array_keys(self::ORDER));
    }

    /**
     * The zone settings for the choices: Smart Cache, the varies, blocked countries.
     *
     * Every vary is sent, on or off, and the lists even when empty, so a
     * choice taken back reaches Bunny.
     *
     * @return array<string, bool|string|list<string>> Settings by Bunny's names.
     */
    public function properties(CachingChoices $choices): array
    {
        $properties = ['EnableSmartCache' => $choices->dynamic];
        $chosen = VaryCache::some($choices->vary);
        foreach (VaryCache::cases() as $vary) {
            $properties[$vary->property()] = in_array($vary, $chosen, true);
        }
        $properties['CacheKeyHeaders'] = implode(',', array_slice(
            array_values(array_filter(CachingChoices::names($choices->varyHeaders), static fn(string $header): bool => CachingChoices::headerProblem($header) === null)),
            0,
            CachingChoices::MAX_HEADERS,
        ));
        $properties['CookieVaryParameters'] = array_slice(
            array_values(array_filter(CachingChoices::names($choices->varyCookies), static fn(string $cookie): bool => CachingChoices::cookieProblem($cookie) === null)),
            0,
            CachingChoices::MAX_COOKIES,
        );
        $properties['BlockedCountries'] = CachingChoices::normaliseCountries($choices->blockedCountries);

        return $properties;
    }

    /**
     * The rules the choices need, by description.
     *
     * A rule with nothing to do, such as skipped paths with no paths, is
     * left out, and plan() deletes it from the zone.
     *
     * @param list<string> $frameworkCookies The framework's session cookies, as SessionCookies gives them.
     * @return array<string, EdgeRuleV2Model>
     */
    public function rules(CachingChoices $choices, array $frameworkCookies): array
    {
        $own = CachingChoices::normaliseSessionCookies($choices->sessionCookies);
        $rules = [$this->description(self::ORIGIN_PRIVATE) => $this->originPrivateRule()];

        $cookies = self::sessionPatterns([...$frameworkCookies, ...$own]);
        if (self::allowed($choices->browserPages, CachingChoices::PAGE_TIMES)) {
            $rules[$this->description(self::BROWSER_PAGES)] = $this->browserRule(self::BROWSER_PAGES, (int) $choices->browserPages, self::triggers(Trigger::ResponseHeader, 'Content-Type', Pattern::MatchAny, self::PAGE_TYPES), $cookies);
        }
        if (self::allowed($choices->browserFiles, CachingChoices::FILE_TIMES)) {
            // A file is the same for everybody, so its time reaches a visitor
            // with a session too; the session rules after it still tell the
            // browser `private` about anything that isn't a static file.
            $rules[$this->description(self::BROWSER_FILES)] = $this->browserRule(self::BROWSER_FILES, (int) $choices->browserFiles, self::triggers(Trigger::ResponseHeader, 'Content-Type', Pattern::MatchNone, self::NOT_FILE_TYPES), []);
        }

        $framework = array_chunk(self::sessionPatterns($frameworkCookies), self::MAX_PATTERNS);
        if (isset($framework[0])) {
            $rules[$this->description(self::SIGNED_IN)] = $this->sessionRule(self::SIGNED_IN, $framework[0]);
        }
        foreach (array_chunk(self::sessionPatterns($own), self::MAX_PATTERNS) as $i => $chunk) {
            if ($i < 2) {
                $rule = $i === 0 ? self::SESSION_COOKIES : self::SESSION_COOKIES_MORE;
                $rules[$this->description($rule)] = $this->sessionRule($rule, $chunk);
            }
        }

        $paths = CachingChoices::normalisePaths($choices->skipPaths);
        if ($paths !== []) {
            $rules[$this->description(self::SKIP_PATHS)] = $this->skipPathsRule($paths);
        }

        return $rules;
    }

    /**
     * The choices a zone carries, from its settings and its rules.
     *
     * @param array<mixed> $zone The zone, as Get Pull Zone answers.
     */
    public function read(array $zone): CachingChoices
    {
        $rules = self::rulesOf($zone);
        $vary = [];
        foreach (VaryCache::cases() as $option) {
            if (!empty($zone[$option->property()])) {
                $vary[] = $option;
            }
        }
        $cookies = [];
        // Two patterns a name; the one at the start of the header is the name
        // with a `*` after it.
        foreach ([self::SESSION_COOKIES, self::SESSION_COOKIES_MORE] as $rule) {
            foreach (self::patternsOf($rules[$this->description($rule)] ?? null) as $pattern) {
                if (!str_starts_with($pattern, '*') && str_ends_with($pattern, '*')) {
                    $cookies[] = substr($pattern, 0, -1);
                }
            }
        }
        $headers = $zone['CacheKeyHeaders'] ?? '';

        return new CachingChoices(
            dynamic: !empty($zone['EnableSmartCache']),
            vary: $vary,
            varyHeaders: CachingChoices::names(explode(',', is_string($headers) ? $headers : '')),
            varyCookies: CachingChoices::names(self::listOf($zone['CookieVaryParameters'] ?? null)),
            blockedCountries: CachingChoices::normaliseCountries(self::listOf($zone['BlockedCountries'] ?? null)),
            skipPaths: CachingChoices::normalisePaths(array_map(
                static fn(string $pattern): string => str_starts_with($pattern, '*/') ? substr($pattern, 1) : $pattern,
                self::patternsOf($rules[$this->description(self::SKIP_PATHS)] ?? null),
            )),
            sessionCookies: CachingChoices::normaliseSessionCookies($cookies),
            browserPages: self::timeOf($rules[$this->description(self::BROWSER_PAGES)] ?? null),
            browserFiles: self::timeOf($rules[$this->description(self::BROWSER_FILES)] ?? null),
        );
    }

    /**
     * The Edge Rule calls that give the zone the rules the choices need.
     *
     * A rule the zone already has as wanted is not sent again; one it has
     * otherwise is replaced in place, keeping its Guid. With $rebuild, every
     * rule this owns is deleted and written again, for a zone whose rules a
     * refusal left half rewritten.
     *
     * With $only, just those rules are planned, by the constants above, as
     * for a form that changes the skipped paths and nothing else.
     *
     * @param array<mixed> $zone The zone, as Get Pull Zone answers.
     * @param list<string> $frameworkCookies The framework's session cookies.
     * @param list<self::ORIGIN_PRIVATE|self::BROWSER_PAGES|self::BROWSER_FILES|self::SIGNED_IN|self::SESSION_COOKIES|self::SESSION_COOKIES_MORE|self::SKIP_PATHS>|null $only
     */
    public function plan(array $zone, CachingChoices $choices, array $frameworkCookies, bool $rebuild = false, ?array $only = null): EdgeRulePlan
    {
        $existing = self::rulesOf($zone);
        $owned = $only === null ? $this->owned() : array_map($this->description(...), $only);
        $wanted = array_intersect_key($this->rules($choices, $frameworkCookies), array_flip($owned));
        $delete = [];
        foreach ($owned as $description) {
            $guid = self::guidOf($existing[$description] ?? null);
            if ($guid !== '' && ($rebuild || !isset($wanted[$description]))) {
                $delete[$guid] = $description;
                unset($existing[$description]);
            }
        }
        $save = [];
        foreach ($wanted as $description => $rule) {
            $have = $existing[$description] ?? null;
            if ($have !== null && self::matches($rule, $have)) {
                continue;
            }
            $guid = self::guidOf($have);
            $save[] = $guid === '' ? $rule : ['Guid' => $guid] + $rule;
        }
        usort($save, static fn(array $a, array $b): int => ($a['OrderIndex'] ?? 0) <=> ($b['OrderIndex'] ?? 0));

        return new EdgeRulePlan($delete, $save);
    }

    /**
     * Never caches a response the origin marked as not for sharing.
     *
     * By the origin's own `Cache-Control` rather than a cookie: every
     * framework says `private` or `no-cache` on a page that belongs to
     * somebody. It decides what is stored, not what is served; a request
     * with a session answered from the cache is the session rules' to stop.
     *
     * @return EdgeRuleV2Model
     */
    private function originPrivateRule(): array
    {
        return [
            'Description' => $this->description(self::ORIGIN_PRIVATE),
            'OrderIndex' => self::ORDER[self::ORIGIN_PRIVATE],
            'Enabled' => true,
            'ActionType' => Action::OverrideCacheTime->value,
            'ActionParameter1' => '0',
            // The strongest of the three words: the origin may have said
            // `no-store`, and `no-cache` alone lets a browser keep it.
            'ExtraActions' => [
                ['ActionType' => Action::BypassPermaCache->value],
                self::privateResponse('private, no-cache, no-store'),
            ],
            'TriggerMatchingType' => Matching::MatchAny->value,
            'Triggers' => self::triggers(Trigger::ResponseHeader, 'Cache-Control', Pattern::MatchAny, self::PRIVATE_CACHE_CONTROL),
        ];
    }

    /**
     * One browser time rule.
     *
     * All of: the response is this kind, it is not private, and the request
     * carries none of the session cookies. Each cookie trigger matches *none*
     * of its patterns, so under *all* they add up to none of every one.
     *
     * @param self::BROWSER_PAGES|self::BROWSER_FILES $rule
     * @param list<TriggerModel> $types
     * @param list<string> $cookies Session cookie patterns; none for files.
     * @return EdgeRuleV2Model
     */
    private function browserRule(string $rule, int $seconds, array $types, array $cookies): array
    {
        return [
            'Description' => $this->description($rule),
            'OrderIndex' => self::ORDER[$rule],
            'Enabled' => true,
            'ActionType' => Action::OverrideBrowserCacheTime->value,
            'ActionParameter1' => (string) $seconds,
            'TriggerMatchingType' => Matching::MatchAll->value,
            'Triggers' => [
                ...$types,
                ...self::triggers(Trigger::ResponseHeader, 'Cache-Control', Pattern::MatchNone, self::PRIVATE_CACHE_CONTROL),
                ...($cookies === [] ? [] : self::triggers(Trigger::RequestHeader, 'Cookie', Pattern::MatchNone, $cookies)),
            ],
        ];
    }

    /**
     * Answers a request carrying a session from the origin, and only from it.
     *
     * The origin would mark the page private, but is never asked when the
     * edge already holds the anonymous copy. Static files are left to the
     * cache: they are the same for everybody.
     *
     * @param self::SIGNED_IN|self::SESSION_COOKIES|self::SESSION_COOKIES_MORE $rule
     * @param list<string> $patterns Cookie patterns, five at most.
     * @return EdgeRuleV2Model
     */
    private function sessionRule(string $rule, array $patterns): array
    {
        return [
            'Description' => $this->description($rule),
            'OrderIndex' => self::ORDER[$rule],
            'Enabled' => true,
            'ActionType' => Action::OverrideCacheTime->value,
            'ActionParameter1' => '0',
            'ExtraActions' => [['ActionType' => Action::BypassPermaCache->value], self::privateResponse('private, no-cache')],
            // All of: one of these cookies, in one trigger, which only holds
            // under *all* while it is one, and a URL that is none of the files.
            'TriggerMatchingType' => Matching::MatchAll->value,
            'Triggers' => [
                ...self::triggers(Trigger::RequestHeader, 'Cookie', Pattern::MatchAny, $patterns),
                ...self::triggers(Trigger::Url, '', Pattern::MatchNone, self::STATIC_FILES),
            ],
        ];
    }

    /**
     * Paths never cached, at the edge or in the browser.
     *
     * @param list<string> $paths Normalised paths.
     * @return EdgeRuleV2Model
     */
    private function skipPathsRule(array $paths): array
    {
        return [
            'Description' => $this->description(self::SKIP_PATHS),
            'OrderIndex' => self::ORDER[self::SKIP_PATHS],
            'Enabled' => true,
            'ActionType' => Action::OverrideCacheTime->value,
            'ActionParameter1' => '0',
            'ExtraActions' => [['ActionType' => Action::BypassPermaCache->value], self::privateResponse('private, no-cache')],
            'TriggerMatchingType' => Matching::MatchAny->value,
            // Bunny matches a URL trigger against the whole URL, so the
            // leading `*` stands for the scheme and hostname.
            'Triggers' => self::triggers(Trigger::Url, '', Pattern::MatchAny, array_map(static fn(string $path): string => '*' . $path, $paths)),
        ];
    }

    /**
     * Tells the browser a response is private, whatever the origin said.
     *
     * @return EdgeRuleV2ActionModel
     */
    private static function privateResponse(string $value): array
    {
        return [
            'ActionType' => Action::SetResponseHeader->value,
            'ActionParameter1' => 'Cache-Control',
            'ActionParameter2' => $value,
        ];
    }

    /**
     * The patterns that find a cookie name in a `Cookie` header.
     *
     * Each name first or after the `; ` between cookies, so a name that only
     * ends in one of these, `_hjSessionUser`, is not taken for it.
     *
     * @param list<string> $names
     * @return list<string>
     */
    private static function sessionPatterns(array $names): array
    {
        $patterns = [];
        foreach ($names as $name) {
            $patterns[] = $name . '*';
            $patterns[] = '*; ' . $name . '*';
        }

        return $patterns;
    }

    /**
     * Patterns as Bunny takes them: five to a trigger.
     *
     * @param list<string> $patterns
     * @return list<TriggerModel>
     */
    private static function triggers(Trigger $type, string $parameter, Pattern $match, array $patterns): array
    {
        $triggers = [];
        foreach (array_chunk($patterns, self::MAX_PATTERNS) as $chunk) {
            $trigger = ['Type' => $type->value, 'PatternMatchingType' => $match->value, 'PatternMatches' => $chunk];
            if ($parameter !== '') {
                $trigger['Parameter1'] = $parameter;
            }
            $triggers[] = $trigger;
        }

        return $triggers;
    }

    /**
     * @param list<int> $allowed
     */
    private static function allowed(?int $seconds, array $allowed): bool
    {
        return $seconds !== null && in_array($seconds, $allowed, true);
    }

    /**
     * Whether a zone's rule already is what is wanted.
     *
     * Only what is wanted is compared: Bunny answers with fields this never
     * sends, such as ActionParameter3 or ReadOnly.
     */
    private static function matches(mixed $want, mixed $have): bool
    {
        if (is_array($want)) {
            if (!is_array($have) || (array_is_list($want) && count($want) !== count($have))) {
                return false;
            }
            foreach ($want as $key => $value) {
                if (!self::matches($value, $have[$key] ?? null)) {
                    return false;
                }
            }

            return true;
        }

        return is_scalar($want) && is_scalar($have) && (string) $want === (string) $have;
    }

    /**
     * A zone's rules by description.
     *
     * @param array<mixed> $zone
     * @return array<string, array<mixed>>
     */
    private static function rulesOf(array $zone): array
    {
        $rules = [];
        foreach (self::listOf($zone['EdgeRules'] ?? null) as $rule) {
            if (is_array($rule) && is_string($rule['Description'] ?? null)) {
                $rules[$rule['Description']] = $rule;
            }
        }

        return $rules;
    }

    /**
     * Every pattern in a rule, across its triggers.
     *
     * @param array<mixed>|null $rule
     * @return list<string>
     */
    private static function patternsOf(?array $rule): array
    {
        $patterns = [];
        foreach (self::listOf($rule['Triggers'] ?? null) as $trigger) {
            foreach (is_array($trigger) ? self::listOf($trigger['PatternMatches'] ?? null) : [] as $pattern) {
                if (is_string($pattern)) {
                    $patterns[] = $pattern;
                }
            }
        }

        return $patterns;
    }

    /**
     * @param array<mixed>|null $rule
     */
    private static function timeOf(?array $rule): ?int
    {
        $seconds = $rule['ActionParameter1'] ?? null;

        return $rule === null ? null : max(0, is_numeric($seconds) ? (int) $seconds : 0);
    }

    /**
     * @param array<mixed>|null $rule
     */
    private static function guidOf(?array $rule): string
    {
        $guid = $rule['Guid'] ?? '';

        return is_string($guid) ? $guid : '';
    }

    /**
     * @return list<mixed>
     */
    private static function listOf(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
