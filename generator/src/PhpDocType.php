<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Generator;

/**
 * Union handling for PHPDoc type strings.
 */
final class PhpDocType
{
    /**
     * @param list<string> $types
     */
    public static function union(array $types): string
    {
        $members = [];
        foreach ($types as $type) {
            foreach (self::members($type) as $member) {
                $members[$member] = true;
            }
        }
        if (isset($members['mixed'])) {
            return 'mixed';
        }

        return implode('|', array_keys($members));
    }

    public static function withNull(string $type): string
    {
        return self::union([$type, 'null']);
    }

    public static function withoutNull(string $type): string
    {
        $members = array_values(array_filter(self::members($type), static fn(string $member): bool => $member !== 'null'));

        return $members === [] ? $type : implode('|', $members);
    }

    /**
     * Splits a type on its top-level "|", leaving unions inside shapes and generics alone.
     *
     * @return list<string>
     */
    public static function members(string $type): array
    {
        $members = [];
        $depth = 0;
        $quoted = false;
        $current = '';
        for ($i = 0, $length = strlen($type); $i < $length; $i++) {
            $char = $type[$i];
            if ($char === "'" && ($i === 0 || $type[$i - 1] !== '\\')) {
                $quoted = !$quoted;
            } elseif (!$quoted && str_contains('{<(', $char)) {
                $depth++;
            } elseif (!$quoted && str_contains('}>)', $char)) {
                $depth--;
            } elseif (!$quoted && $depth === 0 && $char === '|') {
                $members[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $members[] = $current;

        return $members;
    }
}
