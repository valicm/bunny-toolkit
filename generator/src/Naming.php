<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Generator;

/**
 * Turns spec names ("DNS Zone", "PullZonePublic_SetForceSSL", "pull-zone id")
 * into PHP identifiers.
 */
final class Naming
{
    private const MAX_SUMMARY_WORDS = 5;

    /**
     * Names PHP doesn't allow for classes, plus the type keywords PHPStan reserves.
     */
    private const RESERVED_CLASS_NAMES = [
        'abstract', 'and', 'array', 'as', 'bool', 'break', 'callable', 'case', 'catch', 'class', 'clone',
        'const', 'continue', 'declare', 'default', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare',
        'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends',
        'false', 'final', 'finally', 'float', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if',
        'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'int', 'interface', 'isset',
        'iterable', 'list', 'match', 'mixed', 'namespace', 'never', 'new', 'null', 'object', 'or',
        'parent', 'print', 'private', 'protected', 'public', 'readonly', 'require', 'require_once',
        'return', 'self', 'static', 'string', 'switch', 'throw', 'trait', 'true', 'try', 'unset', 'use',
        'var', 'void', 'while', 'xor', 'yield',
    ];

    /**
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $words = [];
        foreach (preg_split('/[^A-Za-z0-9]+/', $text) ?: [] as $part) {
            preg_match_all('/[A-Z]+(?![a-z])|[A-Z]?[a-z]+|[0-9]+/', $part, $matches);
            array_push($words, ...$matches[0]);
        }

        return $words;
    }

    public static function pascal(string $text): string
    {
        $name = implode('', array_map(static fn(string $word): string => ucfirst(strtolower($word)), self::words($text)));
        if ($name === '') {
            return 'Unnamed';
        }

        return ctype_digit($name[0]) ? 'N' . $name : $name;
    }

    public static function camel(string $text): string
    {
        return lcfirst(self::pascal($text));
    }

    /**
     * Keeps names that are already valid identifiers ("PullZoneModel"), so
     * generated types match the spec's schema names.
     */
    public static function identifier(string $text): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $text) === 1 ? $text : self::pascal($text);
    }

    /**
     * Class name from free text such as a tag ("DNS Zone", "WAF"), with
     * acronyms normalised so accessors read well (dnsZone(), waf()).
     */
    public static function className(string $text): string
    {
        $name = self::pascal($text);

        return self::isReservedClassName($name) ? $name . 'Resource' : $name;
    }

    /**
     * Type name from a schema name, kept as-is when already valid so it matches the spec.
     */
    public static function typeName(string $text): string
    {
        $name = self::identifier($text);

        return self::isReservedClassName($name) ? $name . 'Type' : $name;
    }

    public static function isReservedClassName(string $name): bool
    {
        return in_array(strtolower($name), self::RESERVED_CLASS_NAMES, true);
    }

    /**
     * Method name from a short summary ("Get Pull Zone"), otherwise from the
     * operationId ("PullZonePublic_SetForceSSL", "Get Access Lists").
     *
     * Short summaries win because Core's operationIds are ASP.NET action
     * names such as "Index", while long summaries are sentences. Specs with
     * readable operationIds (Shield, Magic Containers) set preferOperationId.
     */
    public static function method(?string $operationId, ?string $summary, string $httpMethod, string $path, bool $preferOperationId = false): string
    {
        $summaryWords = $summary === null ? [] : self::summaryWords($summary);
        $hasOperationId = $operationId !== null && self::words($operationId) !== [];
        if ($summaryWords !== [] && count($summaryWords) <= self::MAX_SUMMARY_WORDS && !($preferOperationId && $hasOperationId)) {
            return self::camel(implode(' ', $summaryWords));
        }
        if ($operationId !== null && $operationId !== '') {
            $name = str_contains($operationId, '_') ? substr($operationId, strrpos($operationId, '_') + 1) : $operationId;
            if (str_ends_with($name, 'Endpoint') && $name !== 'Endpoint') {
                $name = substr($name, 0, -strlen('Endpoint'));
            }
            if (self::words($name) !== []) {
                return self::camel($name);
            }
        }
        if ($summaryWords !== []) {
            return self::camel(implode(' ', $summaryWords));
        }

        return self::camel($httpMethod . ' ' . $path);
    }

    /**
     * Summary words without articles, with "Add/Update" read as "Add Or Update".
     *
     * @return list<string>
     */
    private static function summaryWords(string $summary): array
    {
        $words = self::words(str_replace('/', ' Or ', $summary));

        return array_values(array_filter($words, static fn(string $word): bool => !in_array(strtolower($word), ['a', 'an', 'the'], true)));
    }

    /**
     * @param array<string, true> $taken
     */
    public static function unique(string $name, array &$taken): string
    {
        $candidate = $name;
        for ($i = 2; isset($taken[strtolower($candidate)]); $i++) {
            $candidate = $name . $i;
        }
        $taken[strtolower($candidate)] = true;

        return $candidate;
    }
}
