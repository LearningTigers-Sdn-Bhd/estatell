<?php

namespace App\Data\Folio;

use App\Data\Shared\BadgeData;
use App\Enums\TransactionKind;
use Spatie\LaravelData\Data;

/**
 * One row of the folio page. `state` is old (a line copied from the old system), posted or scheduled. `level` is 0 for a charge or a payment and 1 or 2 for
 * its discount and tax children. `tags` are `FolioRowTag` markers (Scheduled, Overridden, Reversal, Reversed, Refund) with their label and tone. A Posted
 * row has its `lineId` and the `actions` the user may take on it now (reverse, move, remove_discount, refund).
 * Scheduled rows have neither. A payment or refund row carries its `paymentId` and its reference as `note`; a payment
 * also carries `refundable`, the amount left to refund, and `hasReceipt` when the user may download its receipt (on
 * any folio status, so it is not a row action). A row has its `kind`, and `printable` says it can go on a voucher
 * (a Scheduled row, or a posted line that is not a payment, a reversal or a reversed line) for a user who holds
 * `folio.print-voucher`.
 * A live room line of a night carries `correct_price` with its `unitId` and `unitNightId` for a user with
 * `reservations.change-price`. A posted row's `date` is its service date; `postedDate` is its posting date, set only
 * when the line was posted after the day it happened.
 */
class FolioRowData extends Data
{
    /**
     * @param  list<BadgeData>  $tags
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
        public bool $hasReceipt = false,
        public ?TransactionKind $kind = null,
        public bool $printable = false,
        public ?int $unitId = null,
        public ?int $unitNightId = null,
        public ?string $postedDate = null,
    ) {}
}
