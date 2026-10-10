<?php

namespace App\Services\Folio;

/**
 * What a customer document prints for its lines: the groups, the tax summary, the charges subtotal (charges and
 * discounts, without tax) and the total. Amounts are decimal strings with two decimals.
 */
final readonly class DocumentLineGroups
{
    /**
     * @param  list<DocumentLineGroup>  $groups
     * @param  list<DocumentTaxLine>  $taxes
     */
    public function __construct(
        public array $groups,
        public array $taxes,
        public string $chargesSubtotal,
        public string $total,
    ) {}
}
