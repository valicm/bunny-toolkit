<?php

declare(strict_types=1);

/**
 * Downloads the latest bunny.net OpenAPI specs into specs/, pretty-printed
 * so changes show up as readable diffs.
 *
 * Usage: composer update-specs && composer generate
 */

$root = dirname(__DIR__);
/** @var array<string, array{file: string, source: string}> $config */
$config = require __DIR__ . '/config.php';

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
    file_put_contents($root . '/' . $spec['file'], json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n");
    fwrite(\STDOUT, sprintf("%-18s %s\n", $name, $spec['file']));
}

exit($failed ? 1 : 0);
