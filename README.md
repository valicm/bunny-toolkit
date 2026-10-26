# valicm/bunny-toolkit

bunny.net tooling for PHP: a full API client generated from Bunny's official
OpenAPI specs, cache purging within Bunny's rate limits, `CDN-Tag` response
headers, and Edge Script deployment.

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
| `storage($password, StorageRegion::London)` | The zone's region | Storage zone password |
| `stream($libraryKey)` | `video.bunnycdn.com` | Video library API key |

For anything the specs don't cover, call the transport directly:

```php
use Valicm\BunnyToolkit\Http\ApiClient;

$client = new ApiClient($httpClient, $factory, $factory, 'https://logging.bunnycdn.com', $apiKey);
$log = $client->text('GET', $client->path('/{date}/{pullZoneId}.log', ['date' => '10-26-26', 'pullZoneId' => 123]));
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

- **`HashedTagList`** hashes many tags into 12-character tokens, which fits 78
  tags in the header, and purges each with a wildcard pattern.
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
| Exact | Exact URLs, cache tags | 120 | 5 per second |
| Prefix | URLs with `*`, full zone purges | 20 | 0.5 per second |

The purger keeps a local token bucket so it waits instead of collecting 429s.
It waits at most `maxWait` seconds per call, for tokens or for a 429's
`Retry-After`. Whatever it can't purge in that time comes back in
`$result->deferred` unchanged, ready to queue. 5xx and network errors defer the
same way. Other errors, such as a bad API key, are thrown.

Use `maxWait: 0.0` in web requests and a few seconds in queue workers.

`InMemoryTokenBucket` only coordinates purges within one PHP process. Workers
on several hosts need a `RateLimiter` backed by shared storage (e.g. Redis),
and should still expect the odd 429. If Bunny support raises the account's
limits, pass them in:

```php
use Valicm\BunnyToolkit\Purge\PurgeLimit;
use Valicm\BunnyToolkit\Purge\RateLimiter\InMemoryTokenBucket;

$limiter = new InMemoryTokenBucket(exact: new PurgeLimit(burst: 500, refillPerSecond: 20.0));
$purger = $bunny->purger(new HashedTagList(), $limiter);
```

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
- **`responseKinds`:** declare a response body the spec leaves out (the
  storage download is one).
- **`slashParams`:** path parameters that may contain `/`.
- **`experimental`:** leave an API out of the backward compatibility promise.

The specs come from the same URLs bunny.net's own CLI uses.

## Versioning

Releases follow [Semantic Versioning](https://semver.org). Each major version
has a branch (`1.x`), and releases are tags on it. Until `1.0.0`, releases are
pre-releases (`1.0.0-alpha1`, ...), installed with `^1.0@alpha`.

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
- **Wildcard tag purge bucket:** the docs don't say whether a tag purge containing
  `*` uses the exact or prefix bucket. The purger assumes prefix, the smaller one.
- **Silent no-op:** the purge API reportedly answers 204 even when nothing
  matched, so a purged item only means Bunny accepted the request.

## Development

```bash
composer install
composer check      # php-cs-fixer (dry run), PHPStan level max, PHPUnit
composer cs:fix
```

## License

MIT. See [LICENSE](LICENSE).

## Roadmap

- Logging API (no OpenAPI spec published; currently via `ApiClient` directly)
- Pull zone settings and the few remaining edge rules as config files, with diff and apply
- Console commands for CI: `purge`, `script:deploy`, `zone:diff`, `zone:apply`
- Scheduled CI job that runs `update-specs` and opens a pull request
- Drupal module on top: cache-tag invalidator, queue-backed purging, shared rate limiter
