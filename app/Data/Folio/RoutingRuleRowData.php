<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * One rule in the Routing rules Sheet: which unit and charges it matches, the folio they go to and where the rule
 * comes from. Only a Manual rule can be removed.
 */
class RoutingRuleRowData extends Data
{
    public function __construct(
        public int $id,
        public string $unit,
        public string $charges,
        public string $target,
        public string $sourceLabel,
        public bool $canRemove,
    ) {}
}
