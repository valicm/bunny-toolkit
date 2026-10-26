<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Tests\Unit\Generator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Valicm\BunnyToolkit\Generator\TypeMapper;

#[CoversClass(TypeMapper::class)]
final class TypeMapperTest extends TestCase
{
    private TypeMapper $types;

    protected function setUp(): void
    {
        $this->types = new TypeMapper(['components' => ['schemas' => [
            'Rule' => [
                'type' => 'object',
                'required' => ['Name'],
                'properties' => [
                    'Name' => ['type' => 'string'],
                    'Action' => ['$ref' => '#/components/schemas/ActionType'],
                    'Parent' => ['nullable' => true, 'oneOf' => [['$ref' => '#/components/schemas/Rule']]],
                    'Tags' => ['type' => 'array', 'nullable' => true, 'items' => ['type' => 'string']],
                    'Headers' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                    'Mode' => ['type' => 'string', 'enum' => ['on', "it's off"]],
                    'content-type' => ['type' => ['string', 'null']],
                ],
            ],
            'ActionType' => ['type' => 'integer', 'enum' => [0, 1], 'x-enumNames' => ['ForceSSL', 'Redirect']],
            'Status' => ['type' => 'string', 'enum' => ['active', 'paused']],
            'Level' => ['type' => 'integer', 'enum' => [1, 2, 3]],
        ]]], 'App\\Enum');
    }

    public function testObjectSchemasBecomeShapeAliases(): void
    {
        $definition = $this->types->aliasDefinition('Rule');

        self::assertArrayHasKey('fields', $definition);
        self::assertSame([
            ['key' => 'Name', 'optional' => false, 'type' => 'string'],
            ['key' => 'Action', 'optional' => true, 'type' => 'value-of<\\App\\Enum\\ActionType>'],
            ['key' => 'Parent', 'optional' => true, 'type' => 'array<string, mixed>|null'],
            ['key' => 'Tags', 'optional' => true, 'type' => 'list<string>|null'],
            ['key' => 'Headers', 'optional' => true, 'type' => 'array<string, string>'],
            ['key' => 'Mode', 'optional' => true, 'type' => "'on'|'it\\'s off'"],
            ['key' => "'content-type'", 'optional' => true, 'type' => 'string|null'],
        ], $definition['fields']);
    }

    public function testRecursionIsCutAtTheBackReference(): void
    {
        $uses = [];

        self::assertSame('Rule|null', $this->types->type(['nullable' => true, 'oneOf' => [['$ref' => '#/components/schemas/Rule']]], $uses));
        self::assertSame(['Rule' => true], $uses);
    }

    public function testNamedEnumsBecomeEnumClasses(): void
    {
        self::assertSame([
            'ActionType' => ['backing' => 'int', 'cases' => ['ForceSsl' => 0, 'Redirect' => 1], 'description' => null],
            'Status' => ['backing' => 'string', 'cases' => ['Active' => 'active', 'Paused' => 'paused'], 'description' => null],
        ], $this->types->enums());
        self::assertArrayNotHasKey('ActionType', $this->types->aliases());
    }

    public function testUnnamedIntEnumsStayLiteralUnions(): void
    {
        self::assertSame(['type' => '1|2|3'], $this->types->aliasDefinition('Level'));
    }

    public function testNativeTypes(): void
    {
        self::assertSame('int', $this->types->nativeType(['$ref' => '#/components/schemas/ActionType']));
        self::assertSame('string', $this->types->nativeType(['type' => ['string', 'null']]));
        self::assertSame('array', $this->types->nativeType(['nullable' => true, 'oneOf' => [['$ref' => '#/components/schemas/Rule']]]));
        self::assertSame('mixed', $this->types->nativeType([]));
    }

    public function testObjectDetection(): void
    {
        self::assertTrue($this->types->isObject(['$ref' => '#/components/schemas/Rule']));
        self::assertTrue($this->types->isObject(['type' => 'object', 'additionalProperties' => true]));
        self::assertFalse($this->types->isObject(['type' => 'array', 'items' => ['type' => 'integer']]));
    }
}
