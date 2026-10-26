<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\EdgeScript;

use Psr\Http\Client\ClientExceptionInterface;
use Valicm\BunnyToolkit\Api\EdgeScripting\EdgeScriptingApi;
use Valicm\BunnyToolkit\Http\ApiException;

/**
 * Deploys an Edge Script, such as pull-zone middleware, from code kept in a
 * project's repository.
 */
final class EdgeScriptDeployer
{
    public function __construct(
        private readonly EdgeScriptingApi $api,
    ) {}

    /**
     * Upserts variables and secrets, uploads the code, then publishes a release.
     *
     * Variables and secrets go first so new code never runs without the
     * values it expects. Ones not listed are left as they are.
     *
     * @param array<string, string> $variables Name => default value.
     * @param array<string, string> $secrets Name => value.
     * @param bool $publish False to upload without making it live.
     *
     * @throws ApiException
     * @throws ClientExceptionInterface
     */
    public function deploy(
        int $scriptId,
        string $code,
        array $variables = [],
        array $secrets = [],
        ?string $releaseNote = null,
        bool $publish = true,
    ): void {
        foreach ($variables as $name => $value) {
            $this->api->variable()->upsertVariable($scriptId, ['Name' => (string) $name, 'DefaultValue' => $value]);
        }
        foreach ($secrets as $name => $value) {
            $this->api->secret()->upsertSecret($scriptId, ['Name' => (string) $name, 'Secret' => $value]);
        }

        $this->api->code()->setCode($scriptId, ['Code' => $code]);

        if ($publish) {
            $this->api->release()->publishRelease($scriptId, ['Note' => $releaseNote]);
        }
    }
}
