<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Generator;

/**
 * Generates the API, resource, enum and type classes for one OpenAPI spec.
 *
 * @phpstan-type SpecConfig array{
 *     file: string,
 *     source: string,
 *     namespace: string,
 *     title: string,
 *     baseUrl?: string,
 *     defaultResource?: string,
 *     resourceNames?: array<string, string>,
 *     methodNames?: array<string, string>,
 *     slashParams?: list<string>,
 *     preferOperationId?: bool,
 *     responseKinds?: array<string, 'json'|'text'|'stream'|'none'>,
 *     responseSchemas?: array<string, string>,
 *     experimental?: bool,
 * }
 * @phpstan-type Param array{
 *     key: string,
 *     name: string,
 *     wireName: string,
 *     in: string,
 *     required: bool,
 *     schema: mixed,
 *     description: ?string,
 * }
 * @phpstan-type Operation array{
 *     key: string,
 *     httpMethod: string,
 *     path: string,
 *     name: string,
 *     summary: ?string,
 *     description: ?string,
 *     deprecated: bool,
 *     removedOn: ?string,
 *     params: list<Param>,
 *     body: array{kind: 'json'|'raw', required: bool, schema: mixed, description: ?string}|null,
 *     response: array{kind: 'json'|'text'|'stream'|'none', schema: mixed, nullable: bool},
 * }
 * @phpstan-type EnumDefinition array{
 *     backing: 'int'|'string',
 *     cases: array<string, int|string>,
 *     description: ?string,
 *     deprecated: list<string>,
 *     aliases: array<string, string>,
 *     removed: bool,
 * }
 * @phpstan-import-type Surface from ApiSurface
 */
final class SpecGenerator
{
    private const METHODS = ['get', 'put', 'post', 'patch', 'delete'];

    private const AUTH_HEADERS = ['accesskey', 'authorization'];

    private const RESOURCE_IMPORTS = ['ApiClient', 'ApiException', 'ClientExceptionInterface', 'StreamInterface', 'Types'];

    private readonly string $namespace;

    private readonly string $apiClass;

    /**
     * @var array<string, list<Operation>> Resource class => operations.
     */
    private array $resources = [];

    /**
     * @var array<string, string> Resource class => tag description.
     */
    private array $resourceDescriptions = [];

    private TypeMapper $types;

    /**
     * @var Surface The surface generate() produced.
     */
    private array $surface = ['operations' => [], 'enums' => []];

    /**
     * @param array<array-key, mixed> $spec
     * @param SpecConfig $config
     * @param Surface $locked This API's section of generator/api.lock.json.
     * @param bool $keepRemoved Keep locked enum cases the spec no longer has; false when preparing a major version.
     */
    public function __construct(
        private readonly array $spec,
        private readonly array $config,
        string $baseNamespace,
        private readonly array $locked = ['operations' => [], 'enums' => []],
        private readonly bool $keepRemoved = true,
    ) {
        $this->namespace = $baseNamespace . '\\' . $config['namespace'];
        $this->apiClass = $config['namespace'] . 'Api';
    }

    /**
     * @return array<string, string> Path relative to the namespace's directory => file contents.
     */
    public function generate(): array
    {
        $this->collectOperations();
        $this->types = new TypeMapper(
            $this->spec,
            $this->namespace . '\\Enum',
            [...array_keys($this->resources), ...self::RESOURCE_IMPORTS, $this->apiClass],
        );

        $files = [$this->apiClass . '.php' => $this->apiFile()];
        foreach ($this->resources as $class => $operations) {
            $files['Resource/' . $class . '.php'] = $this->resourceFile($class, $operations);
        }
        if ($this->types->aliases() !== []) {
            $files['Types.php'] = $this->typesFile();
        }
        foreach ($this->enums() as $class => $enum) {
            $files['Enum/' . $class . '.php'] = $this->enumFile($class, $enum);
            $this->surface['enums'][$class] = $enum['cases'] + array_map(static fn(string $target): int|string => $enum['cases'][$target], $enum['aliases']);
            if ($enum['deprecated'] !== []) {
                $this->surface['deprecatedEnumCases'][$class] = $enum['deprecated'];
            }
        }

        return $files;
    }

    /**
     * The spec's enums, plus the locked cases and enums Bunny has since removed,
     * which stay until the next major version.
     *
     * @return array<string, EnumDefinition>
     */
    private function enums(): array
    {
        $enums = [];
        foreach ($this->types->enums() as $class => $enum) {
            $enums[$class] = $enum + ['deprecated' => [], 'aliases' => [], 'removed' => false];
        }
        if (!$this->keepRemoved) {
            return $enums;
        }

        foreach ($this->locked['enums'] as $class => $lockedCases) {
            if (!isset($enums[$class])) {
                $ints = array_filter($lockedCases, is_int(...));
                if ($ints !== [] && count($ints) !== count($lockedCases)) {
                    continue;
                }
                $enums[$class] = [
                    'backing' => $ints !== [] ? 'int' : 'string',
                    'cases' => $lockedCases,
                    'description' => null,
                    'deprecated' => array_keys($lockedCases),
                    'aliases' => [],
                    'removed' => true,
                ];
                continue;
            }
            $enum = $enums[$class];
            foreach ($lockedCases as $name => $value) {
                if (isset($enum['cases'][$name])) {
                    // Still there, possibly with a new value, which diff() reports as breaking.
                    continue;
                }
                $renamedTo = array_search($value, $enum['cases'], true);
                if (is_string($renamedTo)) {
                    // Backed enums can't repeat a value, so the old name points at the new case.
                    $enum['aliases'][$name] = $renamedTo;
                } elseif (is_int($value) === ($enum['backing'] === 'int')) {
                    $enum['cases'][$name] = $value;
                } else {
                    continue;
                }
                $enum['deprecated'][] = $name;
            }
            $enums[$class] = $enum;
        }

        return $enums;
    }

    /**
     * The public surface of the code generate() returned, for the lock file.
     *
     * @return Surface
     */
    public function surface(): array
    {
        $surface = $this->surface;
        ksort($surface['operations']);
        ksort($surface['enums']);
        if (isset($surface['deprecatedEnumCases'])) {
            ksort($surface['deprecatedEnumCases']);
        }

        return $surface;
    }

    private function collectOperations(): void
    {
        $tagDescriptions = [];
        foreach (Json::map($this->spec, 'tags') as $tag) {
            $name = Json::str($tag, 'name');
            if ($name !== null) {
                $tagDescriptions[$name] = Json::str($tag, 'description') ?? '';
            }
        }

        $methodNames = [];
        foreach (Json::map($this->spec, 'paths') as $path => $item) {
            $path = (string) $path;
            foreach (self::METHODS as $httpMethod) {
                $operation = Json::map($item, $httpMethod);
                if ($operation === []) {
                    continue;
                }
                $key = strtoupper($httpMethod) . ' ' . $path;
                $tags = Json::map($operation, 'tags');
                $tag = is_string($tags[0] ?? null) ? $tags[0] : ($this->config['defaultResource'] ?? $this->config['namespace']);
                $tagClass = $this->config['resourceNames'][$tag] ?? Naming::className($tag);
                $this->resourceDescriptions[$tagClass] ??= trim($tagDescriptions[$tag] ?? '');

                // Locked operations keep their class and name, whatever the spec now says.
                $locked = $this->locked['operations'][$key] ?? null;
                if ($locked !== null) {
                    [$class, $name] = self::splitMethod($locked['method']);
                } else {
                    $class = $tagClass;
                    $name = $this->config['methodNames'][$key]
                        ?? Naming::method(Json::str($operation, 'operationId'), Json::str($operation, 'summary'), $httpMethod, $path, $this->config['preferOperationId'] ?? false);
                }
                $methodNames[$class][strtolower($name)][] = $key;

                $lockedParams = $locked['params'] ?? [];
                $body = $this->body($operation);
                if ($body !== null && self::lockedAsRequired($lockedParams, 'body')) {
                    $body['required'] = true;
                }

                $this->resources[$class][] = [
                    'key' => $key,
                    'httpMethod' => strtoupper($httpMethod),
                    'path' => $path,
                    'name' => $name,
                    'summary' => Json::str($operation, 'summary'),
                    'description' => Json::str($operation, 'description'),
                    'deprecated' => Json::isTrue($operation, 'deprecated'),
                    'removedOn' => Json::str($operation, RemovedOperations::REMOVED_ON),
                    'params' => $this->params($path, Json::map($item, 'parameters'), Json::map($operation, 'parameters'), $operation, $lockedParams),
                    'body' => $body,
                    'response' => $this->declaredResponse($key) ?? $this->response($operation),
                ];
            }
        }
        ksort($this->resources);

        $collisions = [];
        foreach ($methodNames as $class => $names) {
            foreach ($names as $name => $keys) {
                if (count($keys) > 1) {
                    $collisions[] = sprintf('%s::%s() <- %s', $class, $name, implode(', ', $keys));
                }
            }
        }
        if ($collisions !== []) {
            throw new \RuntimeException(sprintf(
                "Method name collisions in %s; add entries to methodNames in generator/config.php:\n  %s",
                $this->config['file'],
                implode("\n  ", $collisions),
            ));
        }
    }

    /**
     * @param array<array-key, mixed> $shared Path-level parameters.
     * @param array<array-key, mixed> $own Operation parameters, which override shared ones.
     * @param array<array-key, mixed> $operation
     * @param array<string, string> $locked Locked parameter key => signature.
     * @return list<Param>
     */
    private function params(string $path, array $shared, array $own, array $operation, array $locked): array
    {
        $byKey = [];
        foreach ([...$shared, ...$own] as $param) {
            $param = $this->resolveRef($param);
            $name = Json::str($param, 'name');
            $in = Json::str($param, 'in');
            if ($name === null || $in === null || $in === 'cookie') {
                continue;
            }
            if ($in === 'header' && in_array(strtolower($name), self::AUTH_HEADERS, true)) {
                continue;
            }
            $byKey[$in . ':' . $name] = $param;
        }

        preg_match_all('/\{([^}]+)\}/', $path, $matches);
        $placeholders = $matches[1];

        $entries = [];
        foreach ($placeholders as $placeholder) {
            $entries[] = ['path', $placeholder, true, $byKey['path:' . $placeholder] ?? ['schema' => ['type' => 'string']]];
        }
        foreach ($byKey as $param) {
            $in = (string) Json::str($param, 'in');
            if ($in === 'path') {
                // Some specs list path parameters their path never uses.
                continue;
            }
            $entries[] = [$in, (string) Json::str($param, 'name'), Json::isTrue($param, 'required'), $param];
        }

        // Locked names are reserved first, so new parameters can't take them.
        $taken = [];
        if (Json::has($operation, 'requestBody')) {
            $taken['body'] = true;
        }
        foreach ($entries as [$in, $wireName]) {
            if (isset($locked[$in . ':' . $wireName])) {
                $taken[strtolower(ApiSurface::parseParam($locked[$in . ':' . $wireName])['name'])] = true;
            }
        }

        $params = [];
        foreach ($entries as [$in, $wireName, $required, $param]) {
            $key = $in . ':' . $wireName;
            $params[] = [
                'key' => $key,
                'name' => isset($locked[$key]) ? ApiSurface::parseParam($locked[$key])['name'] : Naming::unique(Naming::camel($wireName), $taken),
                'wireName' => $wireName,
                'in' => $in,
                'required' => $required || self::lockedAsRequired($locked, $key),
                'schema' => is_array($param) ? ($param['schema'] ?? null) : null,
                'description' => Json::str($param, 'description'),
            ];
        }

        return $params;
    }

    /**
     * A parameter the spec made optional stays required until the next major
     * version, so calls that pass it positionally keep working.
     *
     * @param array<string, string> $locked
     */
    private static function lockedAsRequired(array $locked, string $key): bool
    {
        return isset($locked[$key]) && !ApiSurface::parseParam($locked[$key])['optional'];
    }

    /**
     * @return array{string, string} Resource class and method name.
     */
    private static function splitMethod(string $method): array
    {
        $parts = explode('::', $method);
        if (count($parts) !== 2) {
            throw new \RuntimeException(sprintf('generator/api.lock.json has an invalid method "%s".', $method));
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * @param array<array-key, mixed> $operation
     * @return array{kind: 'json'|'raw', required: bool, schema: mixed, description: ?string}|null
     */
    private function body(array $operation): ?array
    {
        if (!Json::has($operation, 'requestBody')) {
            return null;
        }
        $body = $this->resolveRef($operation['requestBody']);
        $content = Json::map($body, 'content');
        $json = self::jsonContent($content);
        if ($json === null && $content === []) {
            return null;
        }

        return [
            'kind' => $json !== null ? 'json' : 'raw',
            'required' => Json::isTrue($body, 'required'),
            'schema' => $json !== null ? Json::map($content, $json)['schema'] ?? null : null,
            'description' => Json::str($body, 'description'),
        ];
    }

    /**
     * @param array<array-key, mixed> $operation
     * @return array{kind: 'json'|'text'|'stream'|'none', schema: mixed, nullable: bool}
     */
    private function response(array $operation): array
    {
        $responses = Json::map($operation, 'responses');
        ksort($responses);
        $withContent = null;
        $empty = false;
        foreach ($responses as $code => $response) {
            if (!str_starts_with((string) $code, '2')) {
                continue;
            }
            $content = Json::map($this->resolveRef($response), 'content');
            if ($content === []) {
                $empty = true;
            } else {
                $withContent ??= $content;
            }
        }
        if ($withContent === null) {
            return ['kind' => 'none', 'schema' => null, 'nullable' => false];
        }

        $json = self::jsonContent($withContent);
        if ($json !== null) {
            return ['kind' => 'json', 'schema' => Json::map($withContent, $json)['schema'] ?? null, 'nullable' => $empty];
        }
        foreach (array_keys($withContent) as $type) {
            if (str_starts_with((string) $type, 'text/')) {
                return ['kind' => 'text', 'schema' => null, 'nullable' => false];
            }
        }

        return ['kind' => 'stream', 'schema' => null, 'nullable' => false];
    }

    /**
     * @param array<array-key, mixed> $content
     */
    private static function jsonContent(array $content): ?string
    {
        foreach (array_keys($content) as $type) {
            $type = (string) $type;
            if ($type === 'application/json' || $type === 'text/json' || str_ends_with($type, '+json')) {
                return $type;
            }
        }

        return null;
    }

    /**
     * The response config declares for an operation, where the spec leaves it out.
     *
     * @return array{kind: 'json'|'text'|'stream'|'none', schema: mixed, nullable: bool}|null
     */
    private function declaredResponse(string $key): ?array
    {
        $schema = $this->config['responseSchemas'][$key] ?? null;
        if ($schema !== null) {
            $ref = '#/components/schemas/' . $schema;
            if ($this->resolveRef(['$ref' => $ref]) === null) {
                throw new \RuntimeException(sprintf('responseSchemas for %s names %s, which %s does not define.', $key, $schema, $this->config['file']));
            }

            return ['kind' => 'json', 'schema' => ['$ref' => $ref], 'nullable' => false];
        }
        $kind = $this->config['responseKinds'][$key] ?? null;

        return $kind !== null ? ['kind' => $kind, 'schema' => null, 'nullable' => false] : null;
    }

    private function resolveRef(mixed $node): mixed
    {
        $ref = Json::str($node, '$ref');
        if ($ref === null || !str_starts_with($ref, '#/')) {
            return $node;
        }
        $target = $this->spec;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            $target = is_array($target) ? ($target[$segment] ?? null) : null;
        }

        return $target;
    }

    private function apiFile(): string
    {
        $server = $this->config['baseUrl'] ?? Json::str(Json::map($this->spec, 'servers')[0] ?? null, 'url') ?? '';
        $accessors = [];
        foreach (array_keys($this->resources) as $class) {
            $description = $this->resourceDescriptions[$class] ?? '';
            $accessors[] = self::docblock([$description !== '' ? self::oneLine($description) : $class . ' endpoints.'], '    ')
                . sprintf("    public function %s(): Resource\\%s\n    {\n        return new Resource\\%s(\$this->client);\n    }\n", lcfirst($class), $class, $class);
        }

        return $this->file(
            $this->namespace,
            ['Valicm\\BunnyToolkit\\Http\\ApiClient'],
            self::docblock(['bunny.net ' . $this->config['title'] . '.', ...$this->experimentalNote()]) .
            "final class {$this->apiClass}\n{\n"
            . sprintf("    public const BASE_URL = %s;\n\n", var_export(rtrim($server, '/'), true))
            . "    public function __construct(\n        private readonly ApiClient \$client,\n    ) {}\n\n"
            . implode("\n", $accessors)
            . "}\n",
        );
    }

    /**
     * @param list<Operation> $operations
     */
    private function resourceFile(string $class, array $operations): string
    {
        $uses = [];
        $imports = [
            'Psr\\Http\\Client\\ClientExceptionInterface',
            'Valicm\\BunnyToolkit\\Http\\ApiClient',
            'Valicm\\BunnyToolkit\\Http\\ApiException',
        ];
        $methods = [];
        foreach ($operations as $operation) {
            $methods[] = $this->method($class, $operation, $uses, $imports);
        }
        ksort($uses);
        $imports = array_values(array_unique($imports));
        sort($imports);

        $description = $this->resourceDescriptions[$class] ?? '';
        $doc = [$description !== '' ? self::oneLine($description) : $class . ' endpoints of the bunny.net ' . $this->config['title'] . '.', ...$this->experimentalNote()];
        if ($uses !== []) {
            $doc[] = '';
            foreach (array_keys($uses) as $alias) {
                $doc[] = sprintf('@phpstan-import-type %s from \\%s\\Types', $alias, $this->namespace);
            }
        }

        return $this->file(
            $this->namespace . '\\Resource',
            $imports,
            self::docblock($doc)
            . "final class {$class}\n{\n"
            . "    public function __construct(\n        private readonly ApiClient \$client,\n    ) {}\n\n"
            . implode("\n", $methods)
            . "}\n",
        );
    }

    /**
     * @return list<string> Docblock lines marking an API outside the backward compatibility promise.
     */
    private function experimentalNote(): array
    {
        return ($this->config['experimental'] ?? false)
            ? ['', '@experimental Outside the backward compatibility promise; may change in any release.']
            : [];
    }

    /**
     * @param Operation $operation
     * @param array<string, true> $uses
     * @param list<string> $imports
     */
    private function method(string $class, array $operation, array &$uses, array &$imports): string
    {
        $doc = [];
        $doc[] = self::oneLine($operation['summary'] ?? $operation['name']);
        $doc[] = '';
        $doc[] = '`' . $operation['httpMethod'] . ' ' . $operation['path'] . '`';
        $description = self::oneLine($operation['description'] ?? '');
        if ($description !== '' && $description !== self::oneLine($operation['summary'] ?? '')) {
            $doc[] = '';
            $doc[] = $description;
        }
        $doc[] = '';

        $parameters = [];
        $paramDocs = [];
        foreach ($operation['params'] as $param) {
            $native = $this->types->nativeType($param['schema']);
            $type = $this->types->type($param['schema'], $uses);
            if ($param['in'] !== 'query' && !in_array($native, ['int', 'string', 'float', 'bool'], true)) {
                // Path and header values are always sent as text.
                [$native, $type] = ['string', 'string'];
            }
            // Nullable schemas on required parameters still need a value.
            $type = $param['required'] ? self::withoutNull($type) : self::withNull($type);
            $parameters[] = ['key' => $param['key'], 'required' => $param['required'], 'signature' => self::signature($native, $param['name'], !$param['required'])];
            $paramDocs[] = self::paramDoc($type, $param['name'], $param['description']);
        }

        $bodyArgument = null;
        if ($operation['body'] !== null) {
            $body = $operation['body'];
            if ($body['kind'] === 'raw') {
                $imports[] = 'Psr\\Http\\Message\\StreamInterface';
                $native = 'StreamInterface|string';
                $type = 'StreamInterface|string';
                $bodyArgument = 'raw: $body';
            } else {
                $native = $this->types->nativeType($body['schema']);
                $type = $this->types->type($body['schema'], $uses);
                $bodyArgument = $this->types->isObject($body['schema']) && $native === 'array'
                    ? ($body['required'] ? 'json: (object) $body' : 'json: $body === null ? null : (object) $body')
                    : 'json: $body';
            }
            $type = $body['required'] ? self::withoutNull($type) : self::withNull($type);
            $parameters[] = ['key' => 'body', 'required' => $body['required'], 'signature' => self::signature($native, 'body', !$body['required'])];
            $paramDocs[] = self::paramDoc($type, 'body', $body['description']);
        }
        array_push($doc, ...$paramDocs);

        $response = $operation['response'];
        [$clientMethod, $returnType] = match ($response['kind']) {
            'json' => ['json', 'mixed'],
            'text' => ['text', 'string'],
            'stream' => ['stream', 'StreamInterface'],
            'none' => ['send', 'void'],
        };
        $returnDoc = null;
        if ($response['kind'] === 'json') {
            $returnDoc = $this->types->type($response['schema'], $uses);
            if ($response['nullable']) {
                $returnDoc = self::withNull($returnDoc);
            }
            $doc[] = '@return ' . $returnDoc;
        }
        if ($response['kind'] === 'stream') {
            $imports[] = 'Psr\\Http\\Message\\StreamInterface';
        }
        if ($paramDocs !== [] || $returnDoc !== null) {
            $doc[] = '';
        }
        $doc[] = '@throws ApiException When Bunny answers with an error status.';
        $doc[] = '@throws ClientExceptionInterface When the request cannot be sent.';
        if ($operation['removedOn'] !== null) {
            $doc[] = '';
            $doc[] = sprintf('@deprecated Removed from the bunny.net spec on %s; kept until the next major version.', $operation['removedOn']);
        } elseif ($operation['deprecated']) {
            $doc[] = '';
            $doc[] = '@deprecated Marked deprecated in the bunny.net spec.';
        }

        $arguments = [var_export($operation['httpMethod'], true), $this->pathExpression($operation)];
        $query = $this->arrayArgument($operation['params'], 'query');
        if ($query !== null) {
            $arguments[] = 'query: ' . $query;
        }
        $headers = $this->arrayArgument($operation['params'], 'header');
        if ($headers !== null) {
            $arguments[] = 'headers: ' . $headers;
        }
        if ($bodyArgument !== null) {
            $arguments[] = $bodyArgument;
        }
        $call = sprintf('$this->client->%s(%s)', $clientMethod, implode(', ', $arguments));

        $code = match (true) {
            $returnType === 'void' => "        {$call};\n",
            $returnDoc !== null && $returnDoc !== 'mixed' => "        /** @var {$returnDoc} \$data */\n        \$data = {$call};\n\n        return \$data;\n",
            default => "        return {$call};\n",
        };

        $signature = self::ordered($parameters, $this->locked['operations'][$operation['key']]['params'] ?? []);
        $this->surface['operations'][$operation['key']] = [
            'method' => $class . '::' . $operation['name'],
            'params' => $signature,
            'returns' => $returnType,
        ];
        if ($operation['deprecated'] || $operation['removedOn'] !== null) {
            $this->surface['operations'][$operation['key']]['deprecated'] = true;
        }

        return self::docblock($doc, '    ')
            . sprintf("    public function %s(%s): %s\n    {\n%s    }\n", $operation['name'], implode(', ', $signature), $returnType, $code);
    }

    /**
     * Puts required parameters before optional ones, and each group in locked
     * order, so new optional parameters are added at the end.
     *
     * @param list<array{key: string, required: bool, signature: string}> $parameters In spec order.
     * @param array<string, string> $locked
     * @return array<string, string> Parameter key => signature.
     */
    private static function ordered(array $parameters, array $locked): array
    {
        $positions = array_flip(array_keys($locked));
        usort($parameters, static fn(array $a, array $b): int => [!$a['required'], $positions[$a['key']] ?? \PHP_INT_MAX]
            <=> [!$b['required'], $positions[$b['key']] ?? \PHP_INT_MAX]);

        return array_column($parameters, 'signature', 'key');
    }

    /**
     * @param Operation $operation
     */
    private function pathExpression(array $operation): string
    {
        $pathParams = array_filter($operation['params'], static fn(array $param): bool => $param['in'] === 'path');
        if ($pathParams === []) {
            return var_export($operation['path'], true);
        }
        $values = [];
        $slashes = [];
        foreach ($pathParams as $param) {
            $values[] = var_export($param['wireName'], true) . ' => $' . $param['name'];
            if (in_array($param['wireName'], $this->config['slashParams'] ?? [], true)) {
                $slashes[] = var_export($param['wireName'], true);
            }
        }

        return sprintf(
            '$this->client->path(%s, [%s]%s)',
            var_export($operation['path'], true),
            implode(', ', $values),
            $slashes === [] ? '' : ', keepSlashes: [' . implode(', ', $slashes) . ']',
        );
    }

    /**
     * @param list<Param> $params
     */
    private function arrayArgument(array $params, string $in): ?string
    {
        $entries = [];
        foreach ($params as $param) {
            if ($param['in'] === $in) {
                $entries[] = var_export($param['wireName'], true) . ' => $' . $param['name'];
            }
        }

        return $entries === [] ? null : '[' . implode(', ', $entries) . ']';
    }

    private function typesFile(): string
    {
        $doc = [
            'PHPStan types for the bunny.net ' . $this->config['title'] . ' schemas.',
            '',
            'Import one with `@phpstan-import-type PullZoneModel from \\' . $this->namespace . '\\Types`.',
            '',
        ];
        $aliases = $this->types->aliases();
        ksort($aliases);
        foreach ($aliases as $schemaName => $alias) {
            $definition = $this->types->aliasDefinition($schemaName);
            if (array_key_exists('fields', $definition)) {
                $doc[] = sprintf('@phpstan-type %s array{', $alias);
                foreach ($definition['fields'] as $field) {
                    $doc[] = sprintf('    %s%s: %s,', $field['key'], $field['optional'] ? '?' : '', $field['type']);
                }
                $doc[] = '}';
            } else {
                $doc[] = sprintf('@phpstan-type %s %s', $alias, $definition['type']);
            }
        }

        return $this->file($this->namespace, [], self::docblock($doc) . "final class Types\n{\n}\n");
    }

    /**
     * @param EnumDefinition $enum
     */
    private function enumFile(string $class, array $enum): string
    {
        $kept = '@deprecated Removed from the bunny.net spec; kept until the next major version.';
        $cases = '';
        foreach ($enum['cases'] as $name => $value) {
            if (!$enum['removed'] && in_array($name, $enum['deprecated'], true)) {
                $cases .= self::docblock([$kept], '    ');
            }
            $cases .= sprintf("    case %s = %s;\n", $name, var_export($value, true));
        }
        foreach ($enum['aliases'] as $name => $target) {
            $cases .= self::docblock([sprintf('@deprecated Removed from the bunny.net spec; use %s instead. Kept until the next major version.', $target)], '    ')
                . sprintf("    public const %s = self::%s;\n", $name, $target);
        }
        $description = self::oneLine($enum['description'] ?? '');
        if (preg_match('/^-?\d+ = /', $description) === 1) {
            // NSwag lists "0 = Name 1 = Name", which the cases already say.
            $description = '';
        }
        $doc = [$description !== '' ? $description : $class . ' values in the bunny.net ' . $this->config['title'] . '.'];
        if ($enum['removed']) {
            array_push($doc, '', $kept);
        }

        return $this->file(
            $this->namespace . '\\Enum',
            [],
            self::docblock($doc) . "enum {$class}: {$enum['backing']}\n{\n{$cases}}\n",
        );
    }

    /**
     * @param list<string> $imports
     */
    private function file(string $namespace, array $imports, string $body): string
    {
        $header = "<?php\n\ndeclare(strict_types=1);\n\n"
            . sprintf("// Generated by generator/generate.php from %s. Do not edit.\n\n", $this->config['file'])
            . "namespace {$namespace};\n\n";
        if ($imports !== []) {
            $header .= implode('', array_map(static fn(string $import): string => "use {$import};\n", $imports)) . "\n";
        }

        return $header . $body;
    }

    /**
     * @param list<string> $lines
     */
    private static function docblock(array $lines, string $indent = ''): string
    {
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        $out = $indent . "/**\n";
        foreach ($lines as $line) {
            $out .= $indent . ' *' . ($line === '' ? '' : ' ' . str_replace('*/', '*\\/', $line)) . "\n";
        }

        return $out . $indent . " */\n";
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));
    }

    private static function paramDoc(string $type, string $name, ?string $description): string
    {
        $description = self::oneLine($description ?? '');

        return rtrim(sprintf('@param %s $%s %s', $type, $name, $description));
    }

    private static function signature(string $native, string $name, bool $optional): string
    {
        if (!$optional) {
            return "{$native} \${$name}";
        }
        $nullable = $native === 'mixed' ? 'mixed' : (str_contains($native, '|') ? $native . '|null' : '?' . $native);

        return "{$nullable} \${$name} = null";
    }

    private static function withNull(string $type): string
    {
        return PhpDocType::withNull($type);
    }

    private static function withoutNull(string $type): string
    {
        return PhpDocType::withoutNull($type);
    }
}
