<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Generator;

/**
 * The public PHP surface of one generated API, as recorded in generator/api.lock.json.
 *
 * The generator keeps locked method names, resource classes, parameter names and
 * parameter order, so a spec update can only change the surface in ways
 * diff() reports.
 *
 * @phpstan-type LockedOperation array{method: string, params: array<string, string>, returns: string, deprecated?: true}
 * @phpstan-type Surface array{
 *     operations: array<string, LockedOperation>,
 *     enums: array<string, array<string, int|string>>,
 *     deprecatedEnumCases?: array<string, list<string>>,
 * }
 * @phpstan-type Change array{breaking: bool, message: string}
 */
final class ApiSurface
{
    /**
     * Reads one API's section of the lock file.
     *
     * @return Surface
     */
    public static function fromJson(mixed $data): array
    {
        $surface = ['operations' => [], 'enums' => []];
        foreach (Json::map($data, 'operations') as $key => $operation) {
            $params = [];
            foreach (Json::map($operation, 'params') as $param => $signature) {
                $params[(string) $param] = is_string($signature) ? $signature : throw self::invalid((string) $key);
            }
            $surface['operations'][(string) $key] = [
                'method' => Json::str($operation, 'method') ?? throw self::invalid((string) $key),
                'params' => $params,
                'returns' => Json::str($operation, 'returns') ?? throw self::invalid((string) $key),
            ];
            if (Json::isTrue($operation, 'deprecated')) {
                $surface['operations'][(string) $key]['deprecated'] = true;
            }
        }
        foreach (Json::map($data, 'enums') as $class => $cases) {
            $surface['enums'][(string) $class] = [];
            foreach (is_array($cases) ? $cases : [] as $case => $value) {
                $surface['enums'][(string) $class][(string) $case] = is_int($value) || is_string($value) ? $value : throw self::invalid((string) $class);
            }
        }
        foreach (Json::map($data, 'deprecatedEnumCases') as $class => $cases) {
            $surface['deprecatedEnumCases'][(string) $class] = array_values(array_map(
                static fn(mixed $case): string => is_string($case) ? $case : throw self::invalid((string) $class),
                is_array($cases) ? $cases : [],
            ));
        }

        return $surface;
    }

    /**
     * Lists what changed from $old to $new, flagging changes that break callers.
     *
     * @param Surface $old
     * @param Surface $new
     * @return list<Change>
     */
    public static function diff(array $old, array $new): array
    {
        $changes = [];
        foreach ($old['operations'] as $key => $operation) {
            if (!isset($new['operations'][$key])) {
                $changes[] = self::breaking(sprintf('%s() removed (%s)', $operation['method'], $key));
            }
        }
        foreach ($new['operations'] as $key => $operation) {
            $before = $old['operations'][$key] ?? null;
            if ($before === null) {
                $changes[] = self::compatible(sprintf('%s() added (%s)', $operation['method'], $key));
                continue;
            }
            array_push($changes, ...self::operationDiff($before, $operation));
        }

        foreach ($old['enums'] as $class => $cases) {
            if (!isset($new['enums'][$class])) {
                $changes[] = self::breaking(sprintf('enum %s removed', $class));
                continue;
            }
            foreach ($cases as $case => $value) {
                $now = $new['enums'][$class][$case] ?? null;
                if ($now === null) {
                    $changes[] = self::breaking(sprintf('%s::%s removed', $class, $case));
                } elseif ($now !== $value) {
                    $changes[] = self::breaking(sprintf('%s::%s changed from %s to %s', $class, $case, var_export($value, true), var_export($now, true)));
                }
            }
        }
        foreach ($new['enums'] as $class => $cases) {
            if (!isset($old['enums'][$class])) {
                $changes[] = self::compatible(sprintf('enum %s added', $class));
                continue;
            }
            foreach (array_diff_key($cases, $old['enums'][$class]) as $case => $value) {
                $changes[] = self::compatible(sprintf('%s::%s added', $class, $case));
            }

            $wasDeprecated = $old['deprecatedEnumCases'][$class] ?? [];
            $isDeprecated = $new['deprecatedEnumCases'][$class] ?? [];
            $deprecated = array_values(array_diff($isDeprecated, $wasDeprecated));
            if ($deprecated !== [] && count($isDeprecated) === count($cases) && count($wasDeprecated) < count($old['enums'][$class])) {
                $changes[] = self::compatible(sprintf('enum %s deprecated', $class));
            } else {
                foreach ($deprecated as $case) {
                    $changes[] = self::compatible(sprintf('%s::%s deprecated', $class, $case));
                }
            }
            foreach (array_intersect(array_diff($wasDeprecated, $isDeprecated), array_keys($cases)) as $case) {
                $changes[] = self::compatible(sprintf('%s::%s no longer deprecated', $class, $case));
            }
        }

        return $changes;
    }

    /**
     * Splits a locked parameter ("?int $page = null") into its parts.
     *
     * @return array{type: string, name: string, optional: bool}
     */
    public static function parseParam(string $signature): array
    {
        if (preg_match('/^(?<type>\S+) \$(?<name>\w+)(?<default> = null)?$/', $signature, $match) !== 1) {
            throw new \RuntimeException(sprintf('Cannot parse the locked parameter "%s".', $signature));
        }

        return ['type' => $match['type'], 'name' => $match['name'], 'optional' => ($match['default'] ?? '') !== ''];
    }

    /**
     * @param LockedOperation $old
     * @param LockedOperation $new
     * @return list<Change>
     */
    private static function operationDiff(array $old, array $new): array
    {
        $method = $new['method'] . '()';
        $changes = [];
        if ($old['method'] !== $new['method']) {
            $changes[] = self::breaking(sprintf('%s() renamed to %s', $old['method'], $method));
        }

        foreach ($old['params'] as $key => $signature) {
            $before = self::parseParam($signature);
            if (!isset($new['params'][$key])) {
                $changes[] = self::breaking(sprintf('%s parameter $%s removed', $method, $before['name']));
                continue;
            }
            $now = self::parseParam($new['params'][$key]);
            if ($now['name'] !== $before['name']) {
                $changes[] = self::breaking(sprintf('%s parameter $%s renamed to $%s', $method, $before['name'], $now['name']));
            }
            if (self::baseType($now['type']) !== self::baseType($before['type'])) {
                $changes[] = self::breaking(sprintf('%s parameter $%s changed type from %s to %s', $method, $now['name'], self::baseType($before['type']), self::baseType($now['type'])));
            }
            if ($before['optional'] && !$now['optional']) {
                $changes[] = self::breaking(sprintf('%s parameter $%s is now required', $method, $now['name']));
            }
        }
        foreach (array_diff_key($new['params'], $old['params']) as $signature) {
            $param = self::parseParam($signature);
            $changes[] = $param['optional']
                ? self::compatible(sprintf('%s optional parameter $%s added', $method, $param['name']))
                : self::breaking(sprintf('%s new required parameter $%s', $method, $param['name']));
        }
        // Positional calls break when a kept parameter moves.
        $oldPositions = array_flip(array_keys($old['params']));
        $newPositions = array_flip(array_keys($new['params']));
        foreach (array_intersect_key($newPositions, $oldPositions) as $key => $position) {
            if ($oldPositions[$key] !== $position) {
                $changes[] = self::breaking(sprintf('%s parameter order changed', $method));
                break;
            }
        }

        if ($old['returns'] !== $new['returns']) {
            // Returning something where nothing was returned breaks no caller.
            $changes[] = $old['returns'] === 'void'
                ? self::compatible(sprintf('%s now returns %s', $method, $new['returns']))
                : self::breaking(sprintf('%s return type changed from %s to %s', $method, $old['returns'], $new['returns']));
        }

        $wasDeprecated = $old['deprecated'] ?? false;
        $isDeprecated = $new['deprecated'] ?? false;
        if ($wasDeprecated !== $isDeprecated) {
            $changes[] = self::compatible(sprintf($isDeprecated ? '%s deprecated' : '%s no longer deprecated', $method));
        }

        return $changes;
    }

    private static function baseType(string $type): string
    {
        return (string) preg_replace('/^\?|\|null$/', '', $type);
    }

    /**
     * @return Change
     */
    private static function breaking(string $message): array
    {
        return ['breaking' => true, 'message' => $message];
    }

    /**
     * @return Change
     */
    private static function compatible(string $message): array
    {
        return ['breaking' => false, 'message' => $message];
    }

    private static function invalid(string $key): \RuntimeException
    {
        return new \RuntimeException(sprintf('generator/api.lock.json has an invalid entry for "%s".', $key));
    }
}
