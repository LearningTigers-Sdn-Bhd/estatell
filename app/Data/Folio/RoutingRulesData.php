<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * The Routing rules Sheet: the reservation's rules and the choices of the add form. `categories` are the categories a
 * rule may match and `targets` the Open folios of the reservation. Units and charge codes come from the page's own
 * `units` and `chargeCodes` props.
 */
class RoutingRulesData extends Data
{
    /**
     * @param  list<RoutingRuleRowData>  $rules
     * @param  list<array{value: string, label: string}>  $categories
     * @param  list<FolioOptionData>  $targets
     */
    public function __construct(
        public array $rules,
        public array $categories,
        public array $targets,
    ) {}
}
