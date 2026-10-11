<?php

namespace App\Actions\FrontDesk;

use App\Models\ArInvoice;

/**
 * What `CheckOutGroup` returns: the totals of the save, or the refusal of the first step that refused, tied to its
 * room (`unitId`, null for the Master settle or the whole save) with the rooms a refusal names. A refusal saved
 * nothing. The refusal codes of the steps pass through as they are (`balance_not_settled`, `reference_required`,
 * `units_in_house`, ...) next to the codes below. `paymentIds` are the payments recorded by this save, for the receipts.
 */
class CheckOutGroupResult
{
    public const REFUSAL_NO_BUSINESS_DATE = 'no_business_date';

    public const REFUSAL_UNIT_NOT_FOUND = 'unit_not_found';

    public const REFUSAL_DUPLICATE_ROW = 'duplicate_row';

    public const REFUSAL_ROW_NOT_AVAILABLE = 'row_not_available';

    public const REFUSAL_INVALID_CHOICE = 'invalid_choice';

    public const REFUSAL_NO_MASTER_FOLIO = 'no_master_folio';

    public const REFUSAL_NOTHING_TO_MOVE = 'nothing_to_move';

    public const REFUSAL_PAYMENT_NOT_REFUNDABLE = 'payment_not_refundable';

    public const REFUSAL_INVALID_PAYER = 'invalid_payer';

    public const REFUSAL_AMOUNT_CHANGED = 'amount_changed';

    public const REFUSAL_NOTHING_TO_COLLECT = 'nothing_to_collect';

    /**
     * @param  list<string>  $rooms
     * @param  list<int>  $checkedOutUnitIds
     * @param  list<int>  $settledUnitIds
     * @param  list<int>  $paymentIds
     */
    private function __construct(
        public readonly ?string $refusal,
        public readonly ?int $unitId,
        public readonly array $rooms,
        public readonly array $checkedOutUnitIds,
        public readonly array $settledUnitIds,
        public readonly string $collected,
        public readonly string $refunded,
        public readonly string $movedToMaster,
        public readonly ?ArInvoice $invoice,
        public readonly array $paymentIds,
    ) {}

    /**
     * @param  list<int>  $checkedOutUnitIds  Rooms checked out by this save.
     * @param  list<int>  $settledUnitIds  Rooms that were already out or cancelled and only had money settled.
     * @param  list<int>  $paymentIds
     */
    public static function done(array $checkedOutUnitIds, array $settledUnitIds, string $collected, string $refunded, string $movedToMaster, ?ArInvoice $invoice, array $paymentIds): self
    {
        return new self(null, null, [], $checkedOutUnitIds, $settledUnitIds, $collected, $refunded, $movedToMaster, $invoice, $paymentIds);
    }

    /**
     * @param  list<string>  $rooms
     */
    public static function refused(string $refusal, ?int $unitId = null, array $rooms = []): self
    {
        return new self($refusal, $unitId, $rooms, [], [], '0.00', '0.00', '0.00', null, []);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
