<?php

namespace App\Actions\CityLedger;

use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Models\ArAllocation;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\User;
use App\Services\CityLedger\ArInvoiceBalance;
use App\Services\CityLedger\ArPaymentBalance;
use Illuminate\Support\Facades\DB;

class AllocateArPayment
{
    public function __construct(
        private ArInvoiceBalance $arInvoiceBalance,
        private ArPaymentBalance $arPaymentBalance,
        private SyncArInvoiceStatus $syncArInvoiceStatus,
    ) {}

    /**
     * Match part of a payment to invoices of its company, by invoice id and amount. All lines are saved or none. The
     * payment row is locked first, then the invoices in id order, and every check runs before the first write, so a
     * refusal writes and logs nothing. An invoice that reaches a balance of 0 becomes Settled. Permissions are
     * checked by the route. See docs/15-city-ledger/03-ar-payments.md.
     *
     * @param  array<int, string>  $amounts  Amount by AR invoice id
     */
    public function __invoke(User $actor, ArPayment $payment, array $amounts): ArPaymentResult
    {
        return DB::transaction(function () use ($actor, $payment, $amounts): ArPaymentResult {
            $locked = ArPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->voided_at !== null) {
                return ArPaymentResult::refused(ArPaymentResult::REFUSAL_PAYMENT_NOT_ALLOCATABLE);
            }

            if ($amounts === []) {
                return ArPaymentResult::refused(ArPaymentResult::REFUSAL_AMOUNT_INVALID);
            }

            ksort($amounts);
            $invoices = ArInvoice::query()->whereIn('id', array_keys($amounts))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $total = '0.00';

            foreach ($amounts as $invoiceId => $amount) {
                $invoice = $invoices->get($invoiceId);

                if ($invoice === null
                    || $invoice->property_id !== $locked->property_id
                    || $invoice->company_id !== $locked->company_id
                    || $invoice->type !== ArInvoiceType::Invoice
                    || $invoice->status === ArInvoiceStatus::Void) {
                    return ArPaymentResult::refused(ArPaymentResult::REFUSAL_INVOICE_NOT_ALLOCATABLE);
                }

                if (! preg_match('/^\d+(\.\d{1,2})?$/', (string) $amount) || bccomp((string) $amount, '0', 2) <= 0) {
                    return ArPaymentResult::refused(ArPaymentResult::REFUSAL_AMOUNT_INVALID);
                }

                if (bccomp((string) $amount, $this->arInvoiceBalance->forInvoice($invoice), 2) > 0) {
                    return ArPaymentResult::refused(ArPaymentResult::REFUSAL_EXCEEDS_INVOICE_BALANCE);
                }

                $total = bcadd($total, (string) $amount, 2);
            }

            if (bccomp($total, $this->arPaymentBalance->unallocated($locked), 2) > 0) {
                return ArPaymentResult::refused(ArPaymentResult::REFUSAL_EXCEEDS_UNALLOCATED);
            }

            $allocations = [];

            foreach ($amounts as $invoiceId => $amount) {
                $allocation = new ArAllocation([
                    'ar_payment_id' => $locked->id,
                    'ar_invoice_id' => $invoiceId,
                    'amount' => bcadd((string) $amount, '0', 2),
                    'created_by' => $actor->id,
                ]);
                $allocation->save();
                $allocations[] = $allocation;

                ($this->syncArInvoiceStatus)($invoices->get($invoiceId));
            }

            activity()
                ->performedOn($locked->company)
                ->causedBy($actor)
                ->event('ar_payment.allocated')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'ar_payment_id' => $locked->id,
                    'receipt_number' => $locked->receipt_number,
                    'allocations' => collect($allocations)->map(fn (ArAllocation $one): array => ['ar_invoice_id' => $one->ar_invoice_id, 'amount' => $one->amount])->all(),
                ])
                ->log('AR payment allocated');

            return ArPaymentResult::done($locked, $allocations);
        });
    }
}
