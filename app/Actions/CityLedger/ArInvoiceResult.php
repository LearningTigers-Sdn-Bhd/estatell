<?php

namespace App\Actions\CityLedger;

use App\Models\ArInvoice;

/**
 * What the AR invoice Actions return: the invoice or credit note, or a refusal code. A refusal writes and logs nothing.
 */
class ArInvoiceResult
{
    public const REFUSAL_FOLIO_NOT_OPEN = 'folio_not_open';

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

    private function __construct(
        public readonly ?ArInvoice $invoice,
        public readonly ?string $refusal,
    ) {}

    public static function done(ArInvoice $invoice): self
    {
        return new self($invoice, null);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
