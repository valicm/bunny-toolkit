<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Tests\Unit\Caching;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Valicm\BunnyToolkit\Caching\CachingChoices;
use Valicm\BunnyToolkit\Caching\EdgeRulePlan;
use Valicm\BunnyToolkit\Caching\SessionCookies;
use Valicm\BunnyToolkit\Caching\VaryCache;
use Valicm\BunnyToolkit\Caching\ZoneCaching;

#[CoversClass(ZoneCaching::class)]
#[CoversClass(CachingChoices::class)]
#[CoversClass(EdgeRulePlan::class)]
#[CoversClass(VaryCache::class)]
final class ZoneCachingTest extends TestCase
{
    private function everything(): CachingChoices
    {
        return new CachingChoices(
            dynamic: false,
            vary: [VaryCache::Webp, VaryCache::Cookie],
            varyHeaders: ['Accept-Language', 'accept-language', 'Cookie', 'bad header'],
            varyCookies: ['currency', 'SSESSabc123', 'no;pe'],
            blockedCountries: ['ru', 'XX1', 'RU', 'kp'],
            skipPaths: ['/cart/*', '/', '/*', 'admin', '/cart/*', '/checkout'],
            sessionCookies: ['shop_session', 'a*', 'b', 'c', 'd', 'e', 'f'],
            browserPages: 900,
            browserFiles: 604800,
        );
    }

    public function testProperties(): void
    {
        $properties = (new ZoneCaching())->properties($this->everything());

        self::assertFalse($properties['EnableSmartCache']);
        self::assertTrue($properties['EnableWebpVary']);
        self::assertTrue($properties['EnableCookieVary']);
        self::assertFalse($properties['EnableCountryCodeVary'], 'Every vary is sent, so one taken back reaches Bunny.');
        self::assertSame('Accept-Language', $properties['CacheKeyHeaders']);
        self::assertSame(['currency'], $properties['CookieVaryParameters'], 'A session cookie and a bad name are not varied on.');
        self::assertSame(['RU', 'KP'], $properties['BlockedCountries']);
    }

    public function testRulesFitBunnysLimits(): void
    {
        $rules = (new ZoneCaching('Site: '))->rules($this->everything(), SessionCookies::DRUPAL);

        self::assertSame([
            'Site: never cache what the origin marks private',
            'Site: browser cache time for pages',
            'Site: browser cache time for static files',
            'Site: signed-in visitors skip the cache',
            'Site: your session cookies skip the cache',
            'Site: your session cookies skip the cache (2)',
            'Site: paths never cached',
        ], array_keys($rules));
        foreach ($rules as $description => $rule) {
            self::assertSame($description, $rule['Description'] ?? null);
            self::assertLessThanOrEqual(5, count($rule['Triggers'] ?? []), $description);
            foreach ($rule['Triggers'] ?? [] as $trigger) {
                self::assertLessThanOrEqual(5, count($trigger['PatternMatches'] ?? []), $description);
            }
        }
        self::assertSame(['*/cart/*', '*/checkout'], $rules['Site: paths never cached']['Triggers'][0]['PatternMatches'] ?? null);
    }

    public function testSkipPathsRule(): void
    {
        $rules = (new ZoneCaching('Site: '))->rules(new CachingChoices(skipPaths: ['/admin/*']), []);

        self::assertSame(['Site: never cache what the origin marks private', 'Site: paths never cached'], array_keys($rules), 'No framework, no browser times: nothing else.');
        self::assertEquals([
            'Description' => 'Site: paths never cached',
            'OrderIndex' => 45,
            'Enabled' => true,
            'ActionType' => 3,
            'ActionParameter1' => '0',
            'ExtraActions' => [
                ['ActionType' => 15],
                ['ActionType' => 5, 'ActionParameter1' => 'Cache-Control', 'ActionParameter2' => 'private, no-cache'],
            ],
            'TriggerMatchingType' => 0,
            'Triggers' => [['Type' => 0, 'PatternMatchingType' => 0, 'PatternMatches' => ['*/admin/*']]],
        ], $rules['Site: paths never cached']);
    }

    public function testBrowserTimesOffTheListAreLeftToTheOrigin(): void
    {
        $rules = (new ZoneCaching())->rules(new CachingChoices(browserPages: 60, browserFiles: 86400), []);

        self::assertArrayNotHasKey('bunny-toolkit: browser cache time for pages', $rules);
        self::assertSame('86400', $rules['bunny-toolkit: browser cache time for static files']['ActionParameter1'] ?? null);
    }

    public function testReadsBackWhatItWrites(): void
    {
        $caching = new ZoneCaching('Site: ');
        $choices = $this->everything();
        $zone = $caching->properties($choices) + ['EdgeRules' => array_values($caching->rules($choices, SessionCookies::DRUPAL))];

        $read = $caching->read($zone);

        self::assertSame([VaryCache::Webp, VaryCache::Cookie], $read->vary);
        self::assertSame(['Accept-Language'], $read->varyHeaders);
        self::assertSame(['currency'], $read->varyCookies);
        self::assertSame(['RU', 'KP'], $read->blockedCountries);
        self::assertSame(['/cart/*', '/checkout'], $read->skipPaths);
        self::assertSame(['shop_session', 'b', 'c', 'd', 'e'], $read->sessionCookies, 'Five, and a name with * is dropped.');
        self::assertSame(900, $read->browserPages);
        self::assertSame(604800, $read->browserFiles);
        self::assertTrue($caching->plan($zone, $read, SessionCookies::DRUPAL)->isEmpty(), 'A zone as wanted needs no calls.');
    }

    public function testPlan(): void
    {
        $caching = new ZoneCaching('Site: ');
        $before = new CachingChoices(skipPaths: ['/admin/*'], browserPages: 300);
        $rules = [];
        foreach (array_values($caching->rules($before, SessionCookies::DRUPAL)) as $i => $rule) {
            $rules[] = ['Guid' => 'g' . $i] + $rule;
        }
        $handMade = ['Guid' => 'mine', 'Description' => 'Redirect old blog', 'OrderIndex' => 45];
        $zone = ['EdgeRules' => [...$rules, $handMade]];

        $plan = $caching->plan($zone, $before->withSkipPaths([])->withBrowserCache(3600, null), SessionCookies::DRUPAL);

        self::assertSame(['g3' => 'Site: paths never cached'], $plan->delete, 'The paths rule goes; the hand-made rule stays.');
        self::assertCount(1, $plan->save, 'Only the changed rule is sent.');
        self::assertSame('g1', $plan->save[0]['Guid'] ?? null, 'In place.');
        self::assertSame('3600', $plan->save[0]['ActionParameter1'] ?? null);

        $only = $caching->plan($zone, $before->withSkipPaths([])->withBrowserCache(3600, null), SessionCookies::DRUPAL, only: [ZoneCaching::SKIP_PATHS]);

        self::assertSame(['g3'], array_keys($only->delete));
        self::assertSame([], $only->save, 'The browser time is not this plan\'s.');

        $rebuild = $caching->plan($zone, $before, SessionCookies::DRUPAL, rebuild: true);

        self::assertSame(['g0', 'g1', 'g2', 'g3'], array_keys($rebuild->delete));
        self::assertSame([20, 30, 40, 45], array_map(static fn(array $rule): ?int => $rule['OrderIndex'] ?? null, $rebuild->save));
        self::assertSame([], array_filter($rebuild->save, static fn(array $rule): bool => isset($rule['Guid'])), 'Written as new.');
    }

    public function testNormalising(): void
    {
        self::assertSame(['/a', '/b/*'], CachingChoices::normalisePaths([' /a ', '/', '/*', 'b', '/b/*', '/a', '/x y']));
        self::assertSame(['SESS', 'my.app-1'], CachingChoices::normaliseSessionCookies(['SESS', '*', 'a b', 'x;y', 'my.app-1']));
        self::assertNotNull(CachingChoices::headerProblem('User-Agent'));
        self::assertNull(CachingChoices::headerProblem('Accept-Language'));
        self::assertNotNull(CachingChoices::cookieProblem('PHPSESSID'));
        self::assertSame([VaryCache::Country], VaryCache::some(['country', 'nope', VaryCache::Country]));
    }
}
