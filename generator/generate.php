<?php

declare(strict_types=1);

/**
 * Regenerates src/Api from the specs in specs/, plus the operations kept in
 * specs/removed/, keeping the public surface recorded in generator/api.lock.json.
 *
 * Usage: composer generate
 *        composer generate:allow-breaking   (only when preparing a new major version)
 *
 * --allow-breaking accepts breaking changes and drops everything kept for
 * compatibility: the operations in specs/removed/ (the files are deleted) and
 * the enum cases Bunny removed.
 */

use Valicm\BunnyToolkit\Generator\ApiSurface;
use Valicm\BunnyToolkit\Generator\Json;
use Valicm\BunnyToolkit\Generator\RemovedOperations;
use Valicm\BunnyToolkit\Generator\SpecGenerator;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
$target = $root . '/src/Api';
$lockFile = __DIR__ . '/api.lock.json';
$allowBreaking = in_array('--allow-breaking', (array) ($_SERVER['argv'] ?? []), true);
/** @var array<string, array{file: string, source: string, namespace: string, title: string, defaultResource?: string, resourceNames?: array<string, string>, methodNames?: array<string, string>, slashParams?: list<string>, preferOperationId?: bool, responseKinds?: array<string, 'json'|'text'|'stream'|'none'>, experimental?: bool}> $config */
$config = require __DIR__ . '/config.php';
$lock = is_file($lockFile) ? Json::decodeFile($lockFile) : null;

$files = [];
$surfaces = [];
$changes = [];
foreach ($config as $name => $spec) {
    $namespace = $spec['namespace'];
    $locked = ApiSurface::fromJson($lock[$namespace] ?? []);
    $data = Json::decodeFile($root . '/' . $spec['file']);
    $keptFile = $root . '/' . RemovedOperations::file($spec['file']);
    if (is_file($keptFile) && !$allowBreaking) {
        $data = RemovedOperations::merge($data, Json::decodeFile($keptFile));
    }
    $generator = new SpecGenerator($data, $spec, 'Valicm\\BunnyToolkit\\Api', $locked, keepRemoved: !$allowBreaking);
    foreach ($generator->generate() as $path => $contents) {
        $files[$namespace . '/' . $path] = $contents;
    }
    $surfaces[$namespace] = $generator->surface();
    fwrite(\STDOUT, sprintf("%-18s %s\n", $name, $spec['file']));

    if ($lock === null) {
        continue;
    }
    if (!isset($lock[$namespace])) {
        $changes[] = ['breaking' => false, 'experimental' => false, 'message' => sprintf('%s: new API', $namespace)];
        continue;
    }
    foreach (ApiSurface::diff($locked, $surfaces[$namespace]) as $change) {
        $changes[] = [
            'breaking' => $change['breaking'],
            'experimental' => $spec['experimental'] ?? false,
            'message' => $namespace . ': ' . $change['message'],
        ];
    }
}
foreach (array_diff_key($lock ?? [], $surfaces) as $namespace => $unused) {
    $changes[] = ['breaking' => true, 'experimental' => false, 'message' => sprintf('%s: API removed', $namespace)];
}

$breaking = array_filter($changes, static fn(array $change): bool => $change['breaking'] && !$change['experimental']);
if ($changes !== []) {
    fwrite(\STDOUT, "\nPublic API changes against generator/api.lock.json:\n");
    foreach ($changes as $change) {
        $label = match (true) {
            $change['breaking'] && $change['experimental'] => 'minor*',
            $change['breaking'] => 'BREAKING',
            default => 'minor',
        };
        fwrite(\STDOUT, sprintf("  %-9s %s\n", $label, $change['message']));
    }
    if (array_filter($changes, static fn(array $change): bool => $change['experimental'] && $change['breaking']) !== []) {
        fwrite(\STDOUT, "  * breaking, but the API is experimental and outside the backward compatibility promise\n");
    }
}
if ($lock !== null) {
    fwrite(\STDOUT, sprintf("Release: %s\n", match (true) {
        $breaking !== [] => 'major',
        $changes !== [] => 'minor',
        default => 'patch or none (public API unchanged)',
    }));
}
if ($breaking !== [] && !$allowBreaking) {
    fwrite(\STDERR, "\nNothing written: these changes break callers. They need a new major version;\n"
        . "on that branch, run `composer generate:allow-breaking`.\n");
    exit(1);
}

// Everything under src/Api is generated, so start from an empty directory.
if (is_dir($target)) {
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        /** @var SplFileInfo $entry */
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
}

foreach ($files as $path => $contents) {
    $file = $target . '/' . $path;
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0o775, true);
    }
    file_put_contents($file, $contents);
}
if ($allowBreaking) {
    foreach ($config as $spec) {
        $keptFile = $root . '/' . RemovedOperations::file($spec['file']);
        if (is_file($keptFile)) {
            unlink($keptFile);
            @rmdir(dirname($keptFile));
        }
    }
}
ksort($surfaces);
file_put_contents($lockFile, json_encode($surfaces, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n");

fwrite(\STDOUT, sprintf("Wrote %d files to src/Api and updated generator/api.lock.json.\n", count($files)));
