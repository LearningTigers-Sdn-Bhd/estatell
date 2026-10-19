<?php

namespace App\Actions\Folio;

use App\Models\FolioLine;

/**
 * What `PostCharge`, `ReverseLine`, `TransferLines`, `ApplyDiscount`, `RemoveDiscount`, `PostRoomCharges`, `PostOpeningBalance`, `ChangeOpeningBalance`, `SplitLine`, `RemoveLineTax`, `AddLineTax` and `RecalculateLineTax` return: the lines that were written, or a refusal code.
 * `lines` starts with the main line (the charge, the reversal of the given line, or the first copy on the target),
 * followed by its children. A repeated idempotency key returns the existing lines with `replayed` set and writes nothing.
 */
class PostingResult
{
    public const REFUSAL_NO_BUSINESS_DATE = 'no_business_date';

    public const REFUSAL_UNIT_NOT_POSTABLE = 'unit_not_postable';

    public const REFUSAL_CHARGE_NOT_FOUND = 'charge_not_found';

    public const REFUSAL_QUANTITY_INVALID = 'quantity_invalid';

    public const REFUSAL_AMOUNT_INVALID = 'amount_invalid';

    public const REFUSAL_AMOUNT_OVERRIDE_NOT_ALLOWED = 'amount_override_not_allowed';

    public const REFUSAL_UNIT_NOT_ON_FOLIO = 'unit_not_on_folio';

    public const REFUSAL_FOLIO_NOT_OPEN = 'folio_not_open';

    public const REFUSAL_FOLIO_SETTLED = 'folio_settled_to_city_ledger';

    public const REFUSAL_LINE_IS_CHILD = 'line_is_child';

    public const REFUSAL_ALREADY_REVERSED = 'already_reversed';

    public const REFUSAL_LINE_IS_REVERSAL = 'line_is_reversal';

    public const REFUSAL_TRANSFER_INVALID = 'transfer_invalid';

    public const REFUSAL_DIFFERENT_RESERVATION = 'different_reservation';

    public const REFUSAL_LINE_NOT_TRANSFERABLE = 'line_not_transferable';

    public const REFUSAL_DISCOUNT_NOT_FOUND = 'discount_not_found';

    public const REFUSAL_NOTHING_ELIGIBLE = 'nothing_eligible';

    public const REFUSAL_AMOUNT_REQUIRED = 'amount_required';

    public const REFUSAL_QUOTE_CHANGED = 'quote_changed';

    public const REFUSAL_NOT_A_DISCOUNT = 'not_a_discount';

    public const REFUSAL_PAYMENT_LINE = 'payment_line';

    public const REFUSAL_NIGHT_NOT_FOUND = 'night_not_found';

    public const REFUSAL_SERVICE_DATE_INVALID = 'service_date_invalid';

    public const REFUSAL_TAX_NOT_AVAILABLE = 'tax_not_available';

    public const REFUSAL_SPLIT_INVALID = 'split_invalid';

    public const REFUSAL_SPLIT_TOTAL_MISMATCH = 'split_total_mismatch';

    public const REFUSAL_LINE_HAS_DISCOUNT = 'line_has_discount';

    public const REFUSAL_TAX_NOT_ON_LINE = 'tax_not_on_line';

    public const REFUSAL_TAX_ALREADY_ON_LINE = 'tax_already_on_line';

    public const REFUSAL_TAX_INCLUSIVE = 'tax_inclusive';

    public const REFUSAL_TAX_UNCHANGED = 'tax_unchanged';

    public const REFUSAL_NET_AMOUNT_CHANGED = 'net_amount_changed';

    public const REFUSAL_NIGHT_BEFORE_GO_LIVE = 'night_before_go_live';

    public const REFUSAL_MIGRATION_WINDOW_CLOSED = 'migration_window_closed';

    public const REFUSAL_NOT_OPENING_BALANCE = 'not_opening_balance';

    public const REFUSAL_OPENING_BALANCE_EXISTS = 'opening_balance_exists';

    public const REFUSAL_REASON_REQUIRED = 'reason_required';

    public const REFUSAL_OPENING_AMOUNT_INVALID = 'opening_amount_invalid';

    /**
     * @param  list<FolioLine>  $lines
     */
    private function __construct(
        public readonly array $lines,
        public readonly bool $replayed,
        public readonly ?string $refusal,
    ) {}

    /**
     * @param  list<FolioLine>  $lines
     */
    public static function posted(array $lines): self
    {
        return new self($lines, false, null);
    }

    /**
     * @param  list<FolioLine>  $lines
     */
    public static function replayed(array $lines): self
    {
        return new self($lines, true, null);
    }

    /**
     * The `TaxCalculator` refusal codes pass through as they are.
     */
    public static function refused(string $refusal): self
    {
        return new self([], false, $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }

    public function line(): ?FolioLine
    {
        return $this->lines[0] ?? null;
    }
}
