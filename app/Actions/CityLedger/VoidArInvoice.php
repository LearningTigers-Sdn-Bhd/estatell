<?php

namespace App\Actions\CityLedger;

use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Models\ArAllocation;
use App\Models\ArInvoice;
use App\Models\User;
use App\Services\CityLedger\ArInvoiceBalance;
use Illuminate\Support\Facades\DB;

class VoidArInvoice
{
    public function __construct(private ArInvoiceBalance $arInvoiceBalance) {}

    /**
     * Void an AR invoice or a credit note. An invoice can be voided only while Open with no credit notes that are not
     * Void and no active allocations. Voiding a credit note puts its invoice back to Open when it owes money again. The folio is not touched:
     * staff can reopen it, reverse the City ledger line and settle again. The row is locked first and a refusal
     * writes and logs nothing. Permissions are checked by the route (`city-ledger.invoice` for an invoice,
     * `city-ledger.credit-note` for a credit note). See docs/15-city-ledger/02-ar-invoices.md.
     */
    public function __invoke(User $actor, ArInvoice $invoice): ArInvoiceResult
    {
        return DB::transaction(function () use ($actor, $invoice): ArInvoiceResult {
            $locked = ArInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status === ArInvoiceStatus::Void) {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_ALREADY_VOID);
            }

            $isCreditNote = $locked->type === ArInvoiceType::CreditNote;

            if (! $isCreditNote && ($locked->status !== ArInvoiceStatus::Open || $locked->creditNotes()->where('status', '<>', ArInvoiceStatus::Void)->exists() || ArAllocation::query()->where('ar_invoice_id', $locked->id)->active()->exists())) {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_NOT_VOIDABLE);
            }

            $locked->update(['status' => ArInvoiceStatus::Void]);

            if ($isCreditNote) {
                $parent = ArInvoice::query()->lockForUpdate()->findOrFail($locked->credit_for_invoice_id);

                if ($parent->status === ArInvoiceStatus::Settled && bccomp($this->arInvoiceBalance->forInvoice($parent), '0', 2) > 0) {
                    $parent->update(['status' => ArInvoiceStatus::Open]);
                }
            }

            activity()
                ->performedOn($locked->company)
                ->causedBy($actor)
                ->event('ar_invoice.voided')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'ar_invoice_id' => $locked->id,
                    'number' => $locked->number,
                    'type' => $locked->type->value,
                    'amount' => $locked->amount,
                ])
                ->log('AR document voided');

            return ArInvoiceResult::done($locked);
        });
    }
}
