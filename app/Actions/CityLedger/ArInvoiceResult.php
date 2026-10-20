<?php

namespace App\Actions\CityLedger;

use App\Models\ArInvoice;

/**
 * What the AR invoice Actions return: the invoice or credit note, or a refusal code. A refusal writes and logs nothing.
 */
class ArInvoiceResult
{
    public const REFUSAL_FOLIO_NOT_OPEN = 'folio_not_open';

    public const REFUSAL_UNITS_IN_HOUSE = 'units_in_house';

    public const REFUSAL_RECEIVES_SCHEDULED_CHARGES = 'folio_receives_scheduled_charges';

    public const REFUSAL_NO_COMPANY_PAYER = 'no_company_payer';

    public const REFUSAL_DIRECT_BILL_NOT_ALLOWED = 'direct_bill_not_allowed';

    public const REFUSAL_NOTHING_TO_SETTLE = 'nothing_to_settle';

    public const REFUSAL_NO_BUSINESS_DATE = 'no_business_date';

    public const REFUSAL_NOT_AN_OPEN_INVOICE = 'not_an_open_invoice';

    public const REFUSAL_AMOUNT_INVALID = 'amount_invalid';

    public const REFUSAL_REASON_REQUIRED = 'reason_required';

    public const REFUSAL_CREDIT_EXCEEDS_BALANCE = 'credit_exceeds_balance';

    public const REFUSAL_NOT_VOIDABLE = 'invoice_not_voidable';

    public const REFUSAL_ALREADY_VOID = 'already_void';

    public const REFUSAL_MIGRATION_WINDOW_CLOSED = 'migration_window_closed';

    public const REFUSAL_COMPANY_NOT_AVAILABLE = 'company_not_available';

    public const REFUSAL_ISSUED_AFTER_GO_LIVE = 'issued_after_go_live';

    public const REFUSAL_DUE_BEFORE_ISSUE = 'due_before_issue';

    public const REFUSAL_NUMBER_REQUIRED = 'number_required';

    public const REFUSAL_NUMBER_TAKEN = 'number_taken';

    private function __construct(
        public readonly ?ArInvoice $invoice,
        public readonly ?string $refusal,
        /** @var list<string> */
        public readonly array $rooms = [],
    ) {}

    public static function done(ArInvoice $invoice): self
    {
        return new self($invoice, null);
    }

    /**
     * @param  list<string>  $rooms  The rooms still in-house, for `units_in_house`, or the rooms and dates for
     *                               `folio_receives_scheduled_charges`.
     */
    public static function refused(string $refusal, array $rooms = []): self
    {
        return new self(null, $refusal, $rooms);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
