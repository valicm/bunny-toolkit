<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Caching;

use Psr\Http\Client\ClientExceptionInterface;
use Valicm\BunnyToolkit\Api\Core\CoreApi;
use Valicm\BunnyToolkit\Http\ApiException;

/**
 * The Edge Rule calls that bring a zone to what ZoneCaching wants.
 *
 * Deletes first: Bunny refuses two rules at one OrderIndex, and a rule
 * being replaced may hold the place of one being written.
 *
 * @phpstan-import-type EdgeRuleV2Model from \Valicm\BunnyToolkit\Api\Core\Types
 */
final readonly class EdgeRulePlan
{
    /**
     * @param array<string, string> $delete Rules to delete: description by Guid.
     * @param list<EdgeRuleV2Model> $save Rules to write; one with a Guid replaces that rule.
     */
    public function __construct(
        public array $delete = [],
        public array $save = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->delete === [] && $this->save === [];
    }

    /**
     * Sends the plan, deletes first.
     *
     * @throws ApiException When Bunny refuses a call; the calls before it stand.
     * @throws ClientExceptionInterface When a request cannot be sent.
     */
    public function apply(CoreApi $core, int $pullZoneId): void
    {
        foreach (array_keys($this->delete) as $guid) {
            $core->pullZone()->deleteEdgeRule($pullZoneId, $guid);
        }
        foreach ($this->save as $rule) {
            $core->pullZone()->addOrUpdateEdgeRule($pullZoneId, $rule);
        }
    }
}
