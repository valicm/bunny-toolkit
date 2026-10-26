<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Generator;

/**
 * Maps OpenAPI schemas to PHPStan types.
 *
 * Object schemas become array shapes, published as @phpstan-type aliases on
 * the spec's Types class. Enum schemas with named values become PHP enums,
 * referenced as value-of<Enum> so plain JSON values still type-check.
 * Recursive schemas are cut at the back-reference, which becomes
 * array<string, mixed>, because PHPStan rejects circular aliases.
 *
 * Handles the dialects Bunny's specs use: NSwag (x-enumNames, nullable oneOf
 * wrappers), Swashbuckle (x-enum-varnames) and OpenAPI 3.1 type lists.
 */
final class TypeMapper
{
    private const ANY_OBJECT = 'array<string, mixed>';

    private const MAX_LITERALS = 64;

    /**
     * @var array<string, mixed>
     */
    private array $schemas = [];

    /**
     * Schema name => alias name.
     *
     * @var array<string, string>
     */
    private array $aliases = [];

    /**
     * Schema name => enum class name.
     *
     * @var array<string, string>
     */
    private array $enums = [];

    /**
     * Alias schema name => schema names it must not reference.
     *
     * @var array<string, array<string, true>>
     */
    private array $backEdges = [];

    /**
     * @param array<array-key, mixed> $spec
     * @param list<string> $takenNames Class names visible where aliases are imported.
     */
    public function __construct(
        array $spec,
        private readonly string $enumNamespace,
        array $takenNames = [],
    ) {
        foreach (Json::map(Json::map($spec, 'components'), 'schemas') as $name => $schema) {
            $this->schemas[(string) $name] = $schema;
        }

        $taken = [];
        foreach ($takenNames as $name) {
            $taken[strtolower($name)] = true;
        }
        $enumTaken = [];
        foreach (array_keys($this->schemas) as $name) {
            if ($this->enumCases($name) !== null) {
                $this->enums[$name] = Naming::unique(Naming::typeName($name), $enumTaken);
            } else {
                $this->aliases[$name] = Naming::unique(Naming::typeName($name), $taken);
            }
        }

        $this->findBackEdges();
    }

    /**
     * PHPStan type for a schema used in a method signature.
     *
     * @param array<string, true> $uses Aliases the type refers to, for @phpstan-import-type.
     */
    public function type(mixed $schema, array &$uses): string
    {
        return $this->map($schema, null, $uses);
    }

    /**
     * Native PHP type for a parameter schema.
     */
    public function nativeType(mixed $schema): string
    {
        $schema = $this->resolve($schema);
        foreach (['oneOf', 'anyOf', 'allOf'] as $key) {
            foreach (Json::map($schema, $key) as $member) {
                $member = $this->resolve($member);
                if (!in_array('null', $this->typeNames($member), true)) {
                    return $this->nativeType($member);
                }
            }
        }
        foreach ($this->typeNames($schema) as $type) {
            $native = match ($type) {
                'integer' => 'int',
                'number' => 'float',
                'boolean' => 'bool',
                'string' => 'string',
                'array' => 'array',
                'object' => 'array',
                default => null,
            };
            if ($native !== null) {
                return $native;
            }
        }
        if (Json::has($schema, 'properties') || Json::has($schema, 'additionalProperties')) {
            return 'array';
        }

        return 'mixed';
    }

    /**
     * Whether a schema describes a JSON object, so an empty PHP array has to
     * be sent as {} rather than [].
     */
    public function isObject(mixed $schema): bool
    {
        $schema = $this->resolve($schema);
        $members = [...Json::map($schema, 'oneOf'), ...Json::map($schema, 'anyOf'), ...Json::map($schema, 'allOf')];
        foreach ($members as $member) {
            if ($this->isObject($member)) {
                return true;
            }
        }

        return in_array('object', $this->typeNames($schema), true)
            || Json::has($schema, 'properties')
            || Json::has($schema, 'additionalProperties');
    }

    /**
     * @return array<string, string> Schema name => alias name.
     */
    public function aliases(): array
    {
        return $this->aliases;
    }

    /**
     * Alias definition: shape fields for object schemas, otherwise a single type.
     *
     * @return array{fields: list<array{key: string, optional: bool, type: string}>}|array{type: string}
     */
    public function aliasDefinition(string $schemaName): array
    {
        $uses = [];
        $schema = $this->schemas[$schemaName] ?? null;
        $properties = $this->properties($schema);
        if ($properties !== null) {
            return ['fields' => $this->fields($properties['properties'], $properties['required'], $schemaName, $uses)];
        }

        return ['type' => $this->map($schema, $schemaName, $uses)];
    }

    /**
     * @return array<string, array{backing: 'int'|'string', cases: array<string, int|string>, description: ?string}> Enum class => definition.
     */
    public function enums(): array
    {
        $enums = [];
        foreach ($this->enums as $schemaName => $class) {
            $cases = $this->enumCases($schemaName);
            if ($cases !== null) {
                $enums[$class] = $cases + ['description' => Json::str($this->schemas[$schemaName], 'description')];
            }
        }

        return $enums;
    }

    public function enumClass(string $class): string
    {
        return '\\' . $this->enumNamespace . '\\' . $class;
    }

    /**
     * @param array<string, true> $uses
     */
    private function map(mixed $schema, ?string $context, array &$uses): string
    {
        if (!is_array($schema)) {
            return 'mixed';
        }

        $ref = Json::str($schema, '$ref');
        if ($ref !== null) {
            return $this->nullable($schema, $this->refType(self::refName($ref), $context, $uses));
        }

        foreach (['oneOf', 'anyOf'] as $key) {
            if (Json::map($schema, $key) !== []) {
                $types = [];
                foreach (Json::map($schema, $key) as $member) {
                    $types[] = $this->map($member, $context, $uses);
                }

                return $this->nullable($schema, self::union($types));
            }
        }

        $allOf = Json::map($schema, 'allOf');
        if (count($allOf) === 1) {
            return $this->nullable($schema, $this->map(reset($allOf), $context, $uses));
        }
        $properties = $this->properties($schema);
        if ($allOf !== [] && $properties !== null) {
            return $this->nullable($schema, $this->shape($this->fields($properties['properties'], $properties['required'], $context, $uses)));
        }

        $enum = Json::map($schema, 'enum');
        if ($enum !== [] && count($enum) <= self::MAX_LITERALS) {
            return $this->nullable($schema, self::union(array_values(array_map(self::literal(...), $enum))));
        }

        $types = [];
        foreach ($this->typeNames($schema) as $type) {
            $types[] = match ($type) {
                'integer' => 'int',
                'number' => 'float',
                'boolean' => 'bool',
                'string' => 'string',
                'null' => 'null',
                'array' => 'list<' . $this->map($schema['items'] ?? null, $context, $uses) . '>',
                'object' => $this->objectType($schema, $context, $uses),
                default => 'mixed',
            };
        }
        if ($types === [] && $properties !== null) {
            $types[] = $this->objectType($schema, $context, $uses);
        }
        if ($types === [] && Json::has($schema, 'additionalProperties')) {
            $types[] = $this->objectType($schema, $context, $uses);
        }

        return $types === [] ? 'mixed' : $this->nullable($schema, self::union($types));
    }

    /**
     * @param array<array-key, mixed> $schema
     * @param array<string, true> $uses
     */
    private function objectType(array $schema, ?string $context, array &$uses): string
    {
        $properties = $this->properties($schema);
        if ($properties !== null) {
            return $this->shape($this->fields($properties['properties'], $properties['required'], $context, $uses));
        }

        $additional = $schema['additionalProperties'] ?? null;
        if (is_array($additional) && $additional !== []) {
            return 'array<string, ' . $this->map($additional, $context, $uses) . '>';
        }

        return self::ANY_OBJECT;
    }

    /**
     * Properties and required names of an object schema, merging allOf members.
     *
     * @return array{properties: array<string, mixed>, required: list<string>}|null
     */
    private function properties(mixed $schema): ?array
    {
        $schema = $this->resolve($schema);
        $properties = [];
        $required = [];
        foreach (Json::map($schema, 'allOf') as $member) {
            $merged = $this->properties($member);
            if ($merged !== null) {
                $properties += $merged['properties'];
                array_push($required, ...$merged['required']);
            }
        }
        foreach (Json::map($schema, 'properties') as $name => $property) {
            $properties[(string) $name] = $property;
        }
        foreach (Json::map($schema, 'required') as $name) {
            if (is_string($name)) {
                $required[] = $name;
            }
        }

        return $properties === [] ? null : ['properties' => $properties, 'required' => $required];
    }

    /**
     * @param array<string, mixed> $properties
     * @param list<string> $required
     * @param array<string, true> $uses
     * @return list<array{key: string, optional: bool, type: string}>
     */
    private function fields(array $properties, array $required, ?string $context, array &$uses): array
    {
        $fields = [];
        foreach ($properties as $name => $property) {
            $fields[] = [
                'key' => self::shapeKey($name),
                'optional' => !in_array($name, $required, true),
                'type' => $this->map($property, $context, $uses),
            ];
        }

        return $fields;
    }

    /**
     * @param list<array{key: string, optional: bool, type: string}> $fields
     */
    private function shape(array $fields): string
    {
        $parts = array_map(static fn(array $field): string => $field['key'] . ($field['optional'] ? '?' : '') . ': ' . $field['type'], $fields);

        return 'array{' . implode(', ', $parts) . '}';
    }

    /**
     * @param array<string, true> $uses
     */
    private function refType(string $name, ?string $context, array &$uses): string
    {
        if (isset($this->enums[$name])) {
            return 'value-of<' . $this->enumClass($this->enums[$name]) . '>';
        }
        if (!isset($this->aliases[$name])) {
            return 'mixed';
        }
        if ($context !== null && isset($this->backEdges[$context][$name])) {
            return self::ANY_OBJECT;
        }
        if ($context === null) {
            $uses[$this->aliases[$name]] = true;
        }

        return $this->aliases[$name];
    }

    /**
     * Case names and values for an enum schema whose values can be named.
     *
     * @return array{backing: 'int'|'string', cases: array<string, int|string>}|null
     */
    private function enumCases(string $schemaName): ?array
    {
        $schema = $this->schemas[$schemaName] ?? null;
        $values = Json::map($schema, 'enum');
        if ($values === []) {
            return null;
        }
        $labels = Json::map($schema, 'x-enumNames') ?: Json::map($schema, 'x-enum-varnames');

        $strings = array_filter($values, is_string(...));
        $ints = array_filter($values, is_int(...));
        if (count($strings) === count($values)) {
            $backing = 'string';
        } elseif (count($ints) === count($values) && count($labels) === count($values)) {
            $backing = 'int';
        } else {
            return null;
        }

        $cases = [];
        $taken = [];
        foreach (array_values($values) as $i => $value) {
            /** @var int|string $value */
            $label = $labels[$i] ?? $value;
            $name = Naming::pascal(is_string($label) || is_int($label) ? (string) $label : (string) $value);
            if (strtolower($name) === 'class') {
                $name = 'ClassValue';
            }
            $cases[Naming::unique($name, $taken)] = $value;
        }

        return ['backing' => $backing, 'cases' => $cases];
    }

    /**
     * @return list<string>
     */
    private function typeNames(mixed $schema): array
    {
        $type = is_array($schema) ? ($schema['type'] ?? null) : null;
        if (is_string($type)) {
            return [$type];
        }

        return is_array($type) ? array_values(array_filter($type, is_string(...))) : [];
    }

    private function resolve(mixed $schema): mixed
    {
        for ($depth = 0; $depth < 10; $depth++) {
            $ref = Json::str($schema, '$ref');
            if ($ref === null) {
                return $schema;
            }
            $schema = $this->schemas[self::refName($ref)] ?? null;
        }

        return $schema;
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private function nullable(array $schema, string $type): string
    {
        $nullable = Json::isTrue($schema, 'nullable') || in_array('null', $this->typeNames($schema), true);

        return $nullable ? PhpDocType::withNull($type) : $type;
    }

    /**
     * Depth-first search over alias references, recording edges that close a cycle.
     */
    private function findBackEdges(): void
    {
        $state = [];
        $visit = function (string $name) use (&$visit, &$state): void {
            $state[$name] = 'active';
            foreach ($this->references($this->schemas[$name] ?? null) as $target) {
                if (!isset($this->aliases[$target])) {
                    continue;
                }
                if (($state[$target] ?? null) === 'active') {
                    $this->backEdges[$name][$target] = true;
                } elseif (!isset($state[$target])) {
                    $visit($target);
                }
            }
            $state[$name] = 'done';
        };
        foreach (array_keys($this->aliases) as $name) {
            if (!isset($state[$name])) {
                $visit($name);
            }
        }
    }

    /**
     * @return list<string> Schema names referenced anywhere inside $node.
     */
    private function references(mixed $node): array
    {
        if (!is_array($node)) {
            return [];
        }
        $refs = [];
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $refs[] = self::refName($value);
            } else {
                array_push($refs, ...$this->references($value));
            }
        }

        return $refs;
    }

    private static function refName(string $ref): string
    {
        return substr($ref, strrpos($ref, '/') + 1);
    }

    /**
     * @param list<string> $types
     */
    private static function union(array $types): string
    {
        return PhpDocType::union($types);
    }

    private static function literal(mixed $value): string
    {
        return match (true) {
            is_int($value) => (string) $value,
            is_string($value) => "'" . addcslashes($value, "'\\") . "'",
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            default => 'mixed',
        };
    }

    private static function shapeKey(string $name): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 ? $name : "'" . addcslashes($name, "'\\") . "'";
    }
}
