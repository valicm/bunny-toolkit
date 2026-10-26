<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Generator;

/**
 * Typed reads from decoded JSON.
 */
final class Json
{
    /**
     * @return array<array-key, mixed>
     */
    public static function map(mixed $node, string $key): array
    {
        $value = is_array($node) ? ($node[$key] ?? null) : null;

        return is_array($value) ? $value : [];
    }

    public static function str(mixed $node, string $key): ?string
    {
        $value = is_array($node) ? ($node[$key] ?? null) : null;

        return is_string($value) ? $value : null;
    }

    public static function isTrue(mixed $node, string $key): bool
    {
        return is_array($node) && ($node[$key] ?? null) === true;
    }

    public static function has(mixed $node, string $key): bool
    {
        return is_array($node) && array_key_exists($key, $node);
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function decodeFile(string $file): array
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Cannot read %s.', $file));
        }
        $data = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('%s is not a JSON object.', $file));
        }

        return $data;
    }
}
