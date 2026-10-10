<?php

namespace App\Services\Folio;

/**
 * What a customer document prints for its lines: the groups, the tax summary, the charges subtotal (charges and
 * discounts, without tax) and the total. `showRoomColumn` is true when the lines are one list by date and the document
 * has more than one unit. Amounts are decimal strings with two decimals.
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
        public bool $showRoomColumn = false,
    ) {}
}
