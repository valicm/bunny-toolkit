# Changelog

## Unreleased

- API client for Core, Edge Scripting, Shield, Stream, Edge Storage, Magic Containers, Database and Origin Errors, generated from bunny.net's OpenAPI specs in `specs/` by `generator/`. Request and response arrays are typed with PHPStan shapes; named enums are PHP enums.
- `Http\ApiClient` transport on PSR-18/PSR-17; throws `ApiException` and `RateLimitedException` with Bunny's error details and `Retry-After`.
- `Bunny` entry point, with `StorageRegion` for Edge Storage endpoints.
- `Purger`: tag, URL and full pull-zone purges within Bunny's per-account rate limits, deferring what doesn't fit.
- `InMemoryTokenBucket` rate limiter with Bunny's default limits, overridable.
- `HashedTagList` and `SingleTag` formats for the `CDN-Tag` header.
- `EdgeScriptDeployer`: upserts variables and secrets, uploads code, publishes a release.
- Generated method names, parameter names and parameter order are pinned in `generator/api.lock.json`, so spec updates within a major version only add to the API. Operations and enum cases bunny.net removes stay available, marked `@deprecated`, until the next major version. The Database API is `@experimental`.
- Requires PHP 8.3 or later; tested on 8.3, 8.4 and 8.5.
