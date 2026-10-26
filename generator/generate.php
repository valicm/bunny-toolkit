<?php

declare(strict_types=1);

/**
 * Regenerates src/Api from the specs in specs/.
 *
 * Usage: composer generate
 */

use Valicm\BunnyToolkit\Generator\Json;
use Valicm\BunnyToolkit\Generator\SpecGenerator;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
$target = $root . '/src/Api';
/** @var array<string, array{file: string, source: string, namespace: string, title: string, defaultResource?: string, resourceNames?: array<string, string>, methodNames?: array<string, string>, slashParams?: list<string>, preferOperationId?: bool, responseKinds?: array<string, 'json'|'text'|'stream'|'none'>}> $config */
$config = require __DIR__ . '/config.php';

$files = [];
foreach ($config as $name => $spec) {
    $generator = new SpecGenerator(Json::decodeFile($root . '/' . $spec['file']), $spec, 'Valicm\\BunnyToolkit\\Api');
    foreach ($generator->generate() as $path => $contents) {
        $files[$spec['namespace'] . '/' . $path] = $contents;
    }
    fwrite(\STDOUT, sprintf("%-18s %s\n", $name, $spec['file']));
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

fwrite(\STDOUT, sprintf("Wrote %d files to src/Api.\n", count($files)));
