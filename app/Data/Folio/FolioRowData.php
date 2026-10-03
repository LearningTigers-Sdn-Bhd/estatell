<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * One row of the folio page. `state` is posted or scheduled. `level` is 0 for a charge or a payment and 1 or 2 for
 * its discount and tax children. `tags` are labels such as Scheduled, Overridden, Reversal and Reversed. A Posted
 * row has its `lineId` and the `actions` the user may take on it now (reverse, transfer, remove_discount, refund).
 * Scheduled rows have neither. A payment or refund row carries its `paymentId` and its reference as `note`; a payment
 * also carries `refundable`, the amount left to refund.
 */
class FolioRowData extends Data
{
    /**
     * @param  list<string>  $tags
     * @param  list<string>  $actions
     */
    public function __construct(
        public string $key,
        public string $state,
        public string $date,
        public string $description,
        public int $level,
        public ?string $unit,
        public string $amount,
        public array $tags,
        public ?int $lineId = null,
        public array $actions = [],
        public ?string $note = null,
        public ?int $paymentId = null,
        public ?string $refundable = null,
    ) {}
}
