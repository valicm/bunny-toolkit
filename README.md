# valicm/bunny-toolkit

bunny.net tooling for PHP: a full API client generated from Bunny's official
OpenAPI specs, cache purging within Bunny's rate limits, `CDN-Tag` response
headers, and Edge Script deployment.

The specs are committed in `specs/`, the generator lives in `generator/`, and
the generated client in `src/Api/`. The only runtime dependencies are the PSR
interfaces.

## Requirements

- PHP 8.2+
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
composer generate       # regenerate src/Api from specs/
git diff specs src/Api  # review what Bunny changed
```

Never edit `src/Api` by hand; CI fails if it doesn't match `specs/`. Fix
naming or spec gaps in `generator/config.php` instead:

- **`methodNames`:** rename an operation, e.g. `'POST /purge' => 'purgeUrl'`.
  Generation fails when two operations in a resource get the same name.
- **`responseKinds`:** declare a response body the spec leaves out (the
  storage download is one).
- **`slashParams`:** path parameters that may contain `/`.

The specs come from the same URLs bunny.net's own CLI uses. The Database spec
is served from a `/docs/private/` URL, so treat that API as less stable.

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
