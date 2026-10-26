<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Generator;

/**
 * Keeps operations bunny.net removes from a spec until the next major version.
 *
 * generator/update-specs.php saves each locked operation that disappears from
 * a spec, with the schemas it needs, to specs/removed/<spec>.json. The
 * generator merges them back and marks them deprecated, so an endpoint removed
 * upstream doesn't break callers within a major version.
 */
final class RemovedOperations
{
    /**
     * Operation extension holding the date the operation left the spec.
     */
    public const REMOVED_ON = 'x-removed-on';

    private const METHODS = ['get', 'put', 'post', 'patch', 'delete'];

    /**
     * The file that keeps a spec's removed operations, e.g. specs/removed/core.json.
     */
    public static function file(string $specFile): string
    {
        return dirname($specFile) . '/removed/' . basename($specFile);
    }

    /**
     * Works out what to keep after a spec update.
     *
     * @param array<array-key, mixed> $old The spec before the update.
     * @param array<array-key, mixed> $new The spec after it.
     * @param array<array-key, mixed> $kept The current specs/removed file.
     * @param list<string> $locked Locked operations ("GET /path"); only these are kept.
     * @return array<array-key, mixed> The new specs/removed file, or [] when nothing is kept.
     */
    public static function update(array $old, array $new, array $kept, array $locked, string $today): array
    {
        $newPaths = Json::map($new, 'paths');
        $paths = [];

        // Operations kept earlier stay, unless Bunny brought them back.
        foreach (Json::map($kept, 'paths') as $path => $item) {
            foreach (is_array($item) ? $item : [] as $method => $operation) {
                if (!Json::has($newPaths[$path] ?? null, (string) $method)) {
                    $paths[$path][$method] = $operation;
                }
            }
        }

        foreach (Json::map($old, 'paths') as $path => $item) {
            foreach (self::METHODS as $method) {
                $operation = Json::map($item, $method);
                if ($operation === []
                    || isset($paths[$path][$method])
                    || Json::has($newPaths[$path] ?? null, $method)
                    || !in_array(strtoupper($method) . ' ' . $path, $locked, true)) {
                    continue;
                }
                // The path may be gone too, so the operation carries its path-level parameters.
                $operation['parameters'] = self::inlineParameters(Json::map($item, 'parameters'), Json::map($operation, 'parameters'));
                $operation[self::REMOVED_ON] = $today;
                $paths[$path][$method] = $operation;
            }
        }
        if ($paths === []) {
            return [];
        }
        ksort($paths);

        $components = self::components($paths, Json::map($new, 'components'), [Json::map($old, 'components'), Json::map($kept, 'components')]);

        return $components === [] ? ['paths' => $paths] : ['paths' => $paths, 'components' => $components];
    }

    /**
     * Adds kept operations, and the schemas they need, to a spec.
     *
     * @param array<array-key, mixed> $spec
     * @param array<array-key, mixed> $kept A specs/removed file.
     * @return array<array-key, mixed>
     */
    public static function merge(array $spec, array $kept): array
    {
        $paths = Json::map($spec, 'paths');
        foreach (Json::map($kept, 'paths') as $path => $methods) {
            $item = Json::map($paths, (string) $path);
            foreach (is_array($methods) ? $methods : [] as $method => $operation) {
                // An operation Bunny brought back comes from the spec.
                $item[$method] ??= $operation;
            }
            $paths[$path] = $item;
        }
        $spec['paths'] = $paths;

        $components = Json::map($spec, 'components');
        foreach (Json::map($kept, 'components') as $type => $items) {
            $existing = Json::map($components, (string) $type);
            foreach (is_array($items) ? $items : [] as $name => $item) {
                $existing[$name] ??= $item;
            }
            $components[$type] = $existing;
        }
        $spec['components'] = $components;

        return $spec;
    }

    /**
     * Path-level parameters followed by the operation's own, which override them.
     *
     * @param array<array-key, mixed> $shared
     * @param array<array-key, mixed> $own
     * @return list<mixed>
     */
    private static function inlineParameters(array $shared, array $own): array
    {
        $byKey = [];
        foreach ([...array_values($shared), ...array_values($own)] as $index => $param) {
            $in = Json::str($param, 'in');
            $name = Json::str($param, 'name');
            $byKey[$in !== null && $name !== null ? $in . ':' . $name : 'unnamed:' . $index] = $param;
        }

        return array_values($byKey);
    }

    /**
     * Components the kept operations reference, directly or through other
     * components, that the new spec no longer has.
     *
     * @param array<array-key, mixed> $paths
     * @param array<array-key, mixed> $current Components in the new spec.
     * @param list<array<array-key, mixed>> $sources Components to copy from, in order of preference.
     * @return array<string, array<string, mixed>>
     */
    private static function components(array $paths, array $current, array $sources): array
    {
        $components = [];
        $queue = self::refs($paths);
        while ($queue !== []) {
            [$type, $name] = array_pop($queue);
            if (isset($components[$type][$name]) || Json::has($current[$type] ?? null, $name)) {
                continue;
            }
            foreach ($sources as $source) {
                if (Json::has($source[$type] ?? null, $name)) {
                    $component = Json::map($source, $type)[$name];
                    $components[$type][$name] = $component;
                    array_push($queue, ...self::refs($component));
                    break;
                }
            }
        }
        ksort($components);
        foreach ($components as &$items) {
            ksort($items);
        }

        return $components;
    }

    /**
     * @return list<array{string, string}> Component type and name of each local $ref.
     */
    private static function refs(mixed $node): array
    {
        if (!is_array($node)) {
            return [];
        }
        $refs = [];
        $ref = Json::str($node, '$ref');
        if ($ref !== null && preg_match('#^\#/components/([^/]+)/([^/]+)$#', $ref, $match) === 1) {
            $refs[] = [$match[1], strtr($match[2], ['~1' => '/', '~0' => '~'])];
        }
        foreach ($node as $child) {
            array_push($refs, ...self::refs($child));
        }

        return $refs;
    }
}
