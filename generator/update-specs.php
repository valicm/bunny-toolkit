<?php

declare(strict_types=1);

/**
 * Downloads the latest bunny.net OpenAPI specs into specs/, pretty-printed
 * so changes show up as readable diffs.
 *
 * Operations in generator/api.lock.json that a new spec no longer has are
 * saved to specs/removed/, so the generator keeps them, deprecated, until the
 * next major version.
 *
 * Usage: composer update-specs && composer generate
 */

use Valicm\BunnyToolkit\Generator\ApiSurface;
use Valicm\BunnyToolkit\Generator\Json;
use Valicm\BunnyToolkit\Generator\RemovedOperations;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
/** @var array<string, array{file: string, source: string, namespace: string}> $config */
$config = require __DIR__ . '/config.php';
$lockFile = __DIR__ . '/api.lock.json';
$lock = is_file($lockFile) ? Json::decodeFile($lockFile) : [];
$flags = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

$failed = false;
foreach ($config as $name => $spec) {
    $contents = @file_get_contents($spec['source'], context: stream_context_create([
        'http' => ['timeout' => 30, 'header' => "Accept: application/json\r\n"],
    ]));
    // Decode to objects so empty objects stay {} when re-encoded.
    $data = $contents === false ? null : json_decode($contents);
    if (!$data instanceof stdClass || !isset($data->paths)) {
        fwrite(\STDERR, sprintf("%-18s failed to download %s\n", $name, $spec['source']));
        $failed = true;
        continue;
    }

    $file = $root . '/' . $spec['file'];
    $keptFile = $root . '/' . RemovedOperations::file($spec['file']);
    $kept = RemovedOperations::update(
        is_file($file) ? Json::decodeFile($file) : [],
        (array) json_decode($contents, true),
        is_file($keptFile) ? Json::decodeFile($keptFile) : [],
        array_keys(ApiSurface::fromJson($lock[$spec['namespace']] ?? [])['operations']),
        date('Y-m-d'),
    );

    file_put_contents($file, json_encode($data, $flags) . "\n");
    fwrite(\STDOUT, sprintf("%-18s %s\n", $name, $spec['file']));

    if ($kept !== []) {
        if (!is_dir(dirname($keptFile))) {
            mkdir(dirname($keptFile), 0o775, true);
        }
        file_put_contents($keptFile, json_encode($kept, $flags) . "\n");
        $count = array_sum(array_map(static fn(mixed $item): int => is_array($item) ? count($item) : 0, Json::map($kept, 'paths')));
        fwrite(\STDOUT, sprintf("%-18s %s keeps %d operation(s) removed upstream\n", '', RemovedOperations::file($spec['file']), $count));
    } elseif (is_file($keptFile)) {
        unlink($keptFile);
        // Only succeeds once no spec keeps anything.
        @rmdir(dirname($keptFile));
    }
}

exit($failed ? 1 : 0);
