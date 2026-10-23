<?php

namespace App\Actions\CityLedger;

use App\Actions\Accounting\QueueCompanyBillingSync;
use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Enums\DocumentType;
use App\Models\ArInvoice;
use App\Models\User;
use App\Services\CityLedger\ArInvoiceBalance;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\NightAudit\NextDocumentNumber;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class IssueCreditNote
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private NextDocumentNumber $nextDocumentNumber,
        private ArInvoiceBalance $arInvoiceBalance,
    ) {}

    /**
     * Correct an AR invoice with a credit note for a positive amount (at most two decimals) and a reason. The note is
     * stored negative, takes the next number of the `CreditNote` sequence, is issued on the business date and is
     * Settled at once, because it is applied to its invoice as it is issued. The invoice becomes Settled when its
     * balance reaches 0. The invoice row is locked first and every check runs before the first write, so a refusal
     * writes and logs nothing. Permissions are checked by the route. See docs/15-city-ledger/02-ar-invoices.md.
     */
    public function __invoke(User $actor, ArInvoice $invoice, string $amount, string $reason): ArInvoiceResult
    {
        return DB::transaction(function () use ($actor, $invoice, $amount, $reason): ArInvoiceResult {
            $locked = ArInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->type !== ArInvoiceType::Invoice || $locked->status === ArInvoiceStatus::Void) {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_NOT_AN_OPEN_INVOICE);
            }

            if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount) || BigDecimal::of($amount)->isNegativeOrZero()) {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_AMOUNT_INVALID);
            }

            if (trim($reason) === '') {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_REASON_REQUIRED);
            }

            $balance = $this->arInvoiceBalance->forInvoice($locked);

            if (BigDecimal::of($amount)->isGreaterThan($balance)) {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_CREDIT_EXCEEDS_BALANCE);
            }

            $businessDate = $this->currentBusinessDate->date($locked->property);

            if ($businessDate === null) {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $note = new ArInvoice([
                'company_id' => $locked->company_id,
                'credit_for_invoice_id' => $locked->id,
                'type' => ArInvoiceType::CreditNote,
                'number' => ($this->nextDocumentNumber)($locked->property, DocumentType::CreditNote)->reference,
                'issued_on' => $businessDate,
                'amount' => (string) BigDecimal::of($amount)->negated()->toScale(2),
                'reason' => trim($reason),
                'status' => ArInvoiceStatus::Settled,
                'created_by' => $actor->id,
            ]);
            $note->property_id = $locked->property_id;
            $note->save();

            if (BigDecimal::of($this->arInvoiceBalance->forInvoice($locked))->isZero()) {
                $locked->update(['status' => ArInvoiceStatus::Settled]);
            }

            app(QueueCompanyBillingSync::class)($locked->property_id);

            activity()
                ->performedOn($locked->company)
                ->causedBy($actor)
                ->event('ar_invoice.credit_note_issued')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'ar_invoice_id' => $locked->id,
                    'credit_note_id' => $note->id,
                    'number' => $note->number,
                    'amount' => $note->amount,
                    'reason' => $note->reason,
                ])
                ->log('AR credit note issued');

            return ArInvoiceResult::done($note);
        });
    }
}
