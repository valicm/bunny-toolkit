<?php

declare(strict_types=1);

/**
 * Specs the generator reads, and how each maps to PHP.
 *
 * - source: where generator/update-specs.php downloads the spec from (the
 *   same URLs bunny.net's own CLI uses).
 * - namespace: sub-namespace under Valicm\BunnyToolkit\Api.
 * - methodNames: "METHOD /path" => method name, for operations whose derived
 *   name is unclear or collides. Generation fails on a collision.
 * - resourceNames: tag => resource class name.
 * - defaultResource: resource for operations without a tag.
 * - slashParams: path parameters whose value may contain "/".
 * - preferOperationId: name methods from operationIds even when the summary is short.
 * - responseKinds: "METHOD /path" => json|text|stream|none, where the spec
 *   doesn't describe the response body.
 * - experimental: the API is outside the backward compatibility promise, so
 *   breaking changes to it don't need a major version.
 *
 * Names here only apply to operations that aren't in generator/api.lock.json
 * yet; locked operations keep their names until the next major version.
 */
return [
    'core' => [
        'file' => 'specs/core.json',
        'source' => 'https://core-api-public-docs.b-cdn.net/docs/v3/public.json',
        'namespace' => 'Core',
        'title' => 'Core API',
        'methodNames' => [
            'POST /purge' => 'purgeUrl',
            'POST /pullzone/{id}/purgeCache' => 'purgeCache',
            'PUT /videolibrary/{id}/live/watermark' => 'addLiveWatermark',
            'DELETE /videolibrary/{id}/live/watermark' => 'deleteLiveWatermark',
            'GET /videolibrary/{id}/transcribing/statistics' => 'getTranscribingStatistics',
            'GET /videolibrary/{id}/drm/statistics' => 'getDrmStatistics',
        ],
    ],
    'compute' => [
        'file' => 'specs/compute.json',
        'source' => 'https://core-api-public-docs.b-cdn.net/docs/v3/compute.json',
        'namespace' => 'EdgeScripting',
        'title' => 'Edge Scripting API',
        'methodNames' => [
            'POST /compute/script/{id}/publish/{uuid}' => 'publishByUuid',
        ],
    ],
    'shield' => [
        'file' => 'specs/shield.json',
        'source' => 'https://api.bunny.net/shield/docs/v1/swagger.json',
        'namespace' => 'Shield',
        'title' => 'Shield API',
        'preferOperationId' => true,
        'resourceNames' => ['DDoS' => 'Ddos'],
        'methodNames' => [
            'PATCH /shield/waf/custom-rule/{id}' => 'patchCustomWafRule',
        ],
    ],
    'stream' => [
        'file' => 'specs/stream.json',
        'source' => 'https://video.bunnycdn.com/openapi/bunnynet-video-api.public.json',
        'namespace' => 'Stream',
        'title' => 'Stream API',
    ],
    'storage' => [
        'file' => 'specs/storage.json',
        'source' => 'https://bunny.net/docs/api-reference/storage/openapi.json',
        'namespace' => 'Storage',
        'title' => 'Edge Storage API',
        'slashParams' => ['path'],
        'responseKinds' => [
            // The spec declares no body for a successful download.
            'GET /{storageZoneName}/{path}/{fileName}' => 'stream',
        ],
    ],
    'magic-containers' => [
        'file' => 'specs/magic-containers.json',
        'source' => 'https://api-mc.opsbunny.net/docs/public/swagger.json',
        'namespace' => 'MagicContainers',
        'title' => 'Magic Containers API',
        'preferOperationId' => true,
    ],
    'database' => [
        'file' => 'specs/database.json',
        'source' => 'https://api.bunny.net/database/docs/private/api.json',
        'namespace' => 'Database',
        'title' => 'Database API',
        // Served from a /docs/private/ URL.
        'experimental' => true,
    ],
    'origin-errors' => [
        'file' => 'specs/origin-errors.json',
        'source' => 'https://bunny.net/docs/api-reference/origin-errors/openapi.json',
        'namespace' => 'OriginErrors',
        'title' => 'Origin Errors API',
        'defaultResource' => 'Logs',
        'methodNames' => [
            'GET /{pullZoneId}/{dateTime}' => 'getLogs',
        ],
    ],
];
