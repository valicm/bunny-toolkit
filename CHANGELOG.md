# Changelog

## Unreleased

- Logging API: `Bunny::logging()` searches a pull zone's CDN access logs (`logs()->queryLogs()`) and downloads a day's raw log (`legacyLogs()->getDailyLog()`, a stream). Generated from `specs/logging.json`; the generator's new `baseUrl` option supplies the host its spec leaves out.
- Partner Portal API: `Bunny::partner()` creates, invites and lists customers, and reads their pricing, products, contract, billing and audit log, using a partner account's key. Generated from `specs/partner.json`; `@experimental`.
- `PullZone::addPullZone()` returns the new pull zone (`PullZoneModel`), as Bunny answers, instead of nothing. The generator's new `responseSchemas` option declares a JSON response the spec leaves out.
- Tag purges always count against Bunny's Exact rate-limit bucket, as Bunny's docs say, including `HashedTagList`'s wildcard values. They were counted against the Prefix bucket, which allows a tenth as many.
- `Purger` can send purges concurrently: `concurrency` on `Bunny::purger()`, with `Http\GuzzleConcurrentClient` (or any client implementing `Http\ConcurrentClient`). Each request takes a rate-limit token first; a 429'd item is retried first.
- `Purger` builds every request before sending any, so a tag the format refuses throws before anything is purged.
- `Purger`'s constructor takes the Core `ApiClient` instead of `CoreApi`. `Bunny::purger()` is unchanged.
- `ApiClient::createRequest()` builds a request without sending it, and `ApiClient::sendAll()` sends several, returning each failure in its place.

- `HashedTagList` writes URL-safe base64 tokens instead of 12 hex characters. `hashLength` is 3 to 8, default 5, which fits 170 tags in `CDN-Tag` instead of 78. Tokens change, so purge the pull zone once after upgrading.

- API client for Core, Edge Scripting, Shield, Stream, Edge Storage, Magic Containers, Database and Origin Errors, generated from bunny.net's OpenAPI specs in `specs/` by `generator/`. Request and response arrays are typed with PHPStan shapes; named enums are PHP enums.
- `Http\ApiClient` transport on PSR-18/PSR-17; throws `ApiException` and `RateLimitedException` with Bunny's error details and `Retry-After`.
- `Bunny` entry point, with `StorageRegion` for Edge Storage endpoints.
- `Purger`: tag, URL and full pull-zone purges within Bunny's per-account rate limits, deferring what doesn't fit.
- `InMemoryTokenBucket` rate limiter with Bunny's default limits, overridable.
- `HashedTagList` and `SingleTag` formats for the `CDN-Tag` header.
- `EdgeScriptDeployer`: upserts variables and secrets, uploads code, publishes a release.
- Generated method names, parameter names and parameter order are pinned in `generator/api.lock.json`, so spec updates within a major version only add to the API. Operations and enum cases bunny.net removes stay available, marked `@deprecated`, until the next major version. The Database API is `@experimental`.
- Requires PHP 8.3 or later; tested on 8.3, 8.4 and 8.5.
