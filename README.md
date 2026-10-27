# valicm/bunny-toolkit

[Bunny CDN](https://bunny.net) tooling for PHP: a full API client generated from Bunny's official
OpenAPI specs, cache purging within Bunny's rate limits (shared between
processes through Redis or Valkey), `CDN-Tag` response headers, caching
choices as a pull zone's settings and Edge Rules, and Edge Script deployment.

For Drupal, [drupal/bunny_net](https://www.drupal.org/project/bunny_net) is
built on it: cache tag purging from Drupal's invalidations, a queue for what
the rate limits defer, and the pull zone's settings, caching, Edge Rules and
Edge Scripts kept in config and the repository.

The specs are committed in `specs/`, the generator lives in `generator/`, and
the generated client in `src/Api/`. The only runtime dependencies are the PSR
interfaces.

## Requirements

- PHP 8.3+
- A PSR-18 HTTP client and PSR-17 factories, e.g. Guzzle 7 with
  `GuzzleHttp\Psr7\HttpFactory` (Drupal's `http_client` service works)

## Installation

```bash
composer require valicm/bunny-toolkit
```

## Usage

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Valicm\BunnyToolkit\Bunny;

$factory = new HttpFactory();
$bunny = new Bunny(getenv('BUNNY_API_KEY'), new Client(), $factory, $factory);
```

### API calls

Every operation in the specs is a method, grouped by the spec's tags:

```php
use Valicm\BunnyToolkit\Api\Core\Enum\EdgeRuleActionType;
use Valicm\BunnyToolkit\Api\Core\Enum\TriggerTypes;

$zone = $bunny->core()->pullZone()->getPullZone(123);
echo $zone['Name'];

$bunny->core()->pullZone()->addOrUpdateEdgeRule(123, [
    'ActionType' => EdgeRuleActionType::Redirect->value,
    'ActionParameter1' => 'https://example.com',
    'Triggers' => [['Type' => TriggerTypes::Url->value, 'PatternMatches' => ['*/old/*']]],
    'Enabled' => true,
]);

$bunny->edgeScripting()->edgeScript()->listEdgeScripts();
$bunny->shield()->waf()->getCustomWafRules(456);
```

Request bodies and responses are plain arrays, typed with PHPStan array
shapes generated from the spec's schemas, so PHPStan and PhpStorm check
field names and value types. Enum fields take the enum's `->value`.

Errors are thrown as `Http\ApiException` (with `status`, `errorKey`, `field`),
and 429s as `Http\RateLimitedException` (with `retryAfter` in seconds).

| Method | Base URL | Authenticates with |
|---|---|---|
| `core()` | `api.bunny.net` | Account API key |
| `edgeScripting()` | `api.bunny.net` | Account API key |
| `shield()` | `api.bunny.net` | Account API key |
| `magicContainers()` | `api.bunny.net/mc` | Account API key |
| `database()` | `api.bunny.net/database` | Account API key |
| `originErrors()` | `cdn-origin-logging.bunny.net` | Account API key |
| `logging()` | `logging.bunnycdn.com` | Account API key |
| `partner()` | `api.bunny.net` | Partner account API key |
| `storage($password, StorageRegion::London)` | The zone's region | Storage zone password |
| `stream($libraryKey)` | `video.bunnycdn.com` | Video library API key |

For anything the specs don't cover, call the transport directly:

```php
use Valicm\BunnyToolkit\Api\Core\CoreApi;
use Valicm\BunnyToolkit\Http\ApiClient;

$client = new ApiClient($httpClient, $factory, $factory, CoreApi::BASE_URL, $apiKey);
$edgeServers = $client->json('GET', '/system/edgeserverlist');
```

### Cache tags

Bunny reads a `CDN-Tag` response header, capped at 1024 bytes. The origin
writes it with a `TagFormat`, and the purger must use the same format.

```php
use Valicm\BunnyToolkit\CacheTag\CdnTagHeader;
use Valicm\BunnyToolkit\CacheTag\HashedTagList;

$header = (new HashedTagList())->header($tagsInPriorityOrder);
if (!$header->isEmpty()) {
    $response->headers->set(CdnTagHeader::NAME, $header->value);
}
// $header->dropped lists tags that didn't fit; keep the TTL short enough for them.
```

- **`HashedTagList`** hashes many tags into short URL-safe base64 tokens and
  purges each with a wildcard pattern. `hashLength` trades header room for
  precision:

  | `hashLength` | Tags in the header | A purge also hits a page with a full header |
  |---|---|---|
  | 3 | 255 | 1 in 1,000 |
  | 4 | 204 | 1 in 82,000 |
  | 5 (default) | 170 | 1 in 6.3 million |
  | 6 | 145 | 1 in 470 million |
  | 7 | 127 | 1 in 35 billion |
  | 8 | 113 | 1 in 2.5 trillion |

  A collision never leaves a page stale; it only purges more than needed.
- **`SingleTag`** writes one plain tag and purges it by exact match. Use it for
  responses that depend on one thing, such as a single lot's bidding data.

### Purging

```php
use Valicm\BunnyToolkit\CacheTag\HashedTagList;

$purger = $bunny->purger(new HashedTagList(), maxWait: 0.0);

$result = $purger->purgeTags($pullZoneId, ['node:12', 'commerce_product:7']);
if (!$result->isComplete()) {
    $queue->add($result->deferred, delay: $result->retryAfter);
}

$purger->purgeUrls(['https://example.com/lots/*']);
$purger->purgePullZone($pullZoneId);
```

Bunny limits purges **per account**, so every project on the account shares
the same budget:

| Bucket | Used by | Burst | Refill |
|---|---|---|---|
| Exact | Exact URLs, cache tags, wildcard ones included | 120 | 5 per second |
| Prefix | URLs with `*`, full zone purges | 20 | 0.5 per second |

The purger keeps a local token bucket so it waits instead of collecting 429s.
It waits at most `maxWait` seconds per call, for tokens or for a 429's
`Retry-After`. Whatever it can't purge in that time comes back in
`$result->deferred` unchanged, ready to queue. 5xx and network errors defer the
same way. Other errors, such as a bad API key, are thrown.

Use `maxWait: 0.0` in web requests and a few seconds in queue workers.

`InMemoryTokenBucket` only coordinates purges within one PHP process. Where
several processes or hosts purge on one account (FPM workers, queue workers),
use `RedisTokenBucket`: the buckets live in Redis or Valkey, spent by one Lua
script on the server's clock, so every process draws on the same limit. It
takes a phpredis `\Redis` or a Predis client. If the server stops answering,
the process logs a warning and counts for itself from then on.

```php
use Valicm\BunnyToolkit\Purge\RateLimiter\RedisTokenBucket;

$redis = new \Redis();
$redis->connect('valkey', 6379);
$purger = $bunny->purger(new HashedTagList(), new RedisTokenBucket($redis, logger: $logger));
```

Installations sharing an account but not a server count separately, so they
should still expect the odd 429. If Bunny support raises the account's
limits, pass them in, to either bucket:

```php
use Valicm\BunnyToolkit\Purge\PurgeLimit;
use Valicm\BunnyToolkit\Purge\RateLimiter\InMemoryTokenBucket;

$limiter = new InMemoryTokenBucket(exact: new PurgeLimit(burst: 500, refillPerSecond: 20.0));
$purger = $bunny->purger(new HashedTagList(), $limiter);
```

Bunny takes one tag or URL per request, so purging twenty tags is twenty
round trips. With Guzzle, wrap the client in `GuzzleConcurrentClient` and
pass `concurrency` to send up to that many at once:

```php
use GuzzleHttp\Client;
use Valicm\BunnyToolkit\Http\GuzzleConcurrentClient;

$bunny = new Bunny(getenv('BUNNY_API_KEY'), new GuzzleConcurrentClient(new Client(['timeout' => 5])), $factory, $factory);
$purger = $bunny->purger(new HashedTagList(), concurrency: 10);
```

Each request in a batch takes a rate-limit token first, so a batch is only as
large as the tokens left. What a 429 refuses goes first in the next batch.
Without a `ConcurrentClient`, `concurrency` has no effect and requests go one
after another. Any other PSR-18 client can implement `Http\ConcurrentClient`
for the same.

### Caching rules

`Caching\ZoneCaching` turns caching choices into a pull zone's settings and
Edge Rules, and reads them back, so the zone can be the only record of them.
It sends nothing itself:

```php
use Valicm\BunnyToolkit\Caching\CachingChoices;
use Valicm\BunnyToolkit\Caching\SessionCookies;
use Valicm\BunnyToolkit\Caching\VaryCache;
use Valicm\BunnyToolkit\Caching\ZoneCaching;

$caching = new ZoneCaching('mysite: ');
$choices = new CachingChoices(
    vary: [VaryCache::Webp],
    skipPaths: ['/cart/*'],
    browserPages: 900,
);

$zone = $bunny->core()->pullZone()->getPullZone(12345);
$bunny->core()->pullZone()->updatePullZone(12345, $caching->properties($choices));
$caching->plan($zone, $choices, SessionCookies::DRUPAL)->apply($bunny->core(), 12345);

$caching->read($zone); // CachingChoices, from the zone's settings and rules
```

The rules: a response the origin marks `private`, `no-cache` or `no-store` is
never cached; a request with the framework's session cookie, or one named in
the choices, goes to the origin, except for static files; the paths listed
are never cached; and browsers keep pages and files for the times chosen,
never a private response and never a page with a session. Each rule's
description starts with the prefix given, which is how `plan()` finds it
again; rules with other descriptions are left alone. They take the order
indexes 20 to 45.

`plan()` skips rules the zone already has as wanted, updates the rest in
place, and deletes rules the choices no longer need. `only:` limits it to some
rules; `rebuild: true` deletes and writes them all again.

### Edge Script deployment

Keep middleware code in the project's repository and deploy it from CI:

```php
$bunny->edgeScriptDeployer()->deploy(
    scriptId: 4567,
    code: file_get_contents(__DIR__ . '/edge/middleware.ts'),
    variables: ['ORIGIN_HOST' => 'www.example.com'],
    secrets: ['ORIGIN_SECRET' => getenv('ORIGIN_SECRET')],
    releaseNote: 'Deploy ' . getenv('GIT_COMMIT'),
);
```

Variables and secrets are upserted before the code, so new code never runs
without them. Ones not listed are left alone.

## Updating the API client

```bash
composer update-specs   # download the latest specs into specs/
composer generate       # regenerate src/Api; prints public API changes and the release they need
git diff specs src/Api  # review what Bunny changed
```

A scheduled workflow (`.github/workflows/update-specs.yml`) runs both every
Monday and opens one pull request, `update-specs`, with the changes and the
release `generate` says they need; it updates that pull request on later runs
while it is open. A pull request opened with the workflow's own token starts no
other workflows, so for CI to run on it, set a `SPECS_PR_TOKEN` secret: a
fine-grained token with contents and pull requests write on this repository.
The repository also has to allow GitHub Actions to create pull requests.

When Bunny removes an operation that's in the lock, `update-specs` saves it,
with the schemas it needs, to `specs/removed/<spec>.json`. `generate` keeps
generating it, marked `@deprecated`, until the next major version. If Bunny
brings it back, the saved copy is deleted. Enum cases Bunny removes are kept
the same way, from the lock; a renamed case keeps its old name as a deprecated
constant pointing at the new case.

Never edit `src/Api` by hand; CI fails if it doesn't match `specs/` and
`generator/api.lock.json`. Fix naming or spec gaps in `generator/config.php`
instead:

- **`methodNames`:** name a new operation, e.g. `'POST /purge' => 'purgeUrl'`.
  Generation fails when two operations in a resource get the same name.
  Operations already in the lock keep their names (see [Versioning](#versioning)).
- **`baseUrl`:** the API's base URL, for a spec that lists no servers (the
  Logging API's).
- **`responseKinds`:** declare a response body the spec leaves out (the
  storage download is one).
- **`responseSchemas`:** name the component schema of a JSON response the
  spec leaves out (`addPullZone()` returns the new zone).
- **`slashParams`:** path parameters that may contain `/`.
- **`experimental`:** leave an API out of the backward compatibility promise.

The specs come from the same URLs Bunny CDN's own CLI uses. The Partner
Portal spec, which the CLI doesn't read, is the one Bunny CDN's docs index
links to.

## Versioning

Releases follow [Semantic Versioning](https://semver.org). Each major version
has a branch (`1.x`), and releases are tags on it, from `1.0.0`; install with
`^1.0`. The `1.0.0-alpha` pre-releases before it were outside these rules.

| Change | Release |
|---|---|
| Bug fixes, or generator and documentation changes that leave the public API alone | patch |
| Spec updates that add operations, optional parameters, enums or enum cases | minor |
| New features in the hand-written classes, and deprecations | minor |
| Dropping a PHP version once php.net has ended its security support | minor |
| Removing or renaming a class, method, parameter or enum case; changing a parameter's type or position; making a parameter required | major |
| Changing what a `TagFormat` writes into `CDN-Tag` | major |

A `TagFormat` change is major even without a signature change: responses
already cached carry the old tags, and a purger using the new format can't
purge them.

Not covered by these rules:

- **The Database API** is `@experimental`: its spec is served from a
  `/docs/private/` URL, so it may change in any release.
- **The Partner Portal API** is `@experimental`: it is new and open only to
  Bunny CDN partners, so it may change in any release.
- **PHPStan array shapes** follow Bunny's specs and can change in a minor
  release. Requests and responses are plain arrays, so a shape change can
  surface new PHPStan errors but doesn't change what runs.

### Keeping the generated API stable

`generator/api.lock.json` records each operation's resource class, method name,
parameter names, parameter order and return type, and each enum's cases.
`composer generate` builds on it:

- Locked operations keep their class and name, even when Bunny rewrites a
  summary or moves the operation to another tag.
- New optional parameters go at the end of the signature.
- A parameter Bunny makes optional stays required, so positional calls keep
  working.
- Operations and enum cases Bunny removes are kept, marked `@deprecated`.
- It prints what changed against the lock and the release that needs, and
  writes nothing when a change breaks callers.

Other breaking changes from Bunny (a parameter removed, a type changed) wait
for the next major version. On its branch, run
`composer generate:allow-breaking`: it accepts them and drops what was kept for
compatibility, deleting `specs/removed/`. To also regenerate every name and
parameter order from the current specs, delete the lock first.

## Bunny behaviour to verify on a test pull zone

These are documented by third parties or inferred, not confirmed by Bunny's docs:

- **Single tag per header:** a [Sulu integration](https://packagist.org/packages/alengo/sulu-bunny-cdn-bundle)
  reports that Bunny stores the whole `CDN-Tag` value as one tag. `HashedTagList`
  is built on that.
- **Wildcard tag purge bucket:** the docs put tag purges in the Exact bucket
  without saying whether a tag containing `*` is an exception. The purger
  counts every tag purge as Exact; a 429 still backs off if Bunny counts them
  as Prefix.
- **Silent no-op:** the purge API reportedly answers 204 even when nothing
  matched, so a purged item only means Bunny accepted the request.

## Development

```bash
composer install
composer check      # php-cs-fixer (dry run), PHPStan level max, PHPUnit
BUNNY_TOOLKIT_REDIS=tcp://127.0.0.1:6379 composer test   # also RedisTokenBucket's script, on a real server
composer cs:fix
```

## License

MIT. See [LICENSE](LICENSE).

## Roadmap

- Stream live streaming (12 operations) and Core's `POST /videolibrary/resetApiKey`:
  only in the copies of those specs on Bunny CDN's docs site, not in the specs
  `update-specs` downloads. Needs the generator to merge a second source's
  extra paths into a spec
- Verify on a real account what is so far tested only against the specs: the
  Logging API's date format, and the Partner Portal API (needs a partner account)
- Pull zone settings and Edge Rules from files, with diff and apply, outside
  Drupal: drupal/bunny_net has both for Drupal sites, and caching's rules are
  already `Caching\ZoneCaching`. Moving bunny_net's rule-file format and
  settings diff here would serve the console commands below
- Console commands for CI: `purge`, `script:deploy`, `zone:diff`, `zone:apply`
