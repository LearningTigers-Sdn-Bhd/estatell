<?php

namespace App\Actions\CityLedger;

use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\User;
use App\Services\CityLedger\ArInvoiceBalance;
use App\Services\CityLedger\ArPaymentBalance;
use Illuminate\Support\Facades\DB;

class AutoAllocateArPayment
{
    public function __construct(
        private AllocateArPayment $allocateArPayment,
        private ArInvoiceBalance $arInvoiceBalance,
        private ArPaymentBalance $arPaymentBalance,
    ) {}

    /**
     * Fill the company's open invoices oldest first (by `due_on`, then id) with what the payment has left. What is
     * left after the last invoice stays as credit, and nothing owed makes no allocation. It runs `AllocateArPayment`,
     * so the same locks and checks apply. See docs/15-city-ledger/03-ar-payments.md.
     */
    public function __invoke(User $actor, ArPayment $payment): ArPaymentResult
    {
        return DB::transaction(function () use ($actor, $payment): ArPaymentResult {
            $locked = ArPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->voided_at !== null) {
                return ArPaymentResult::refused(ArPaymentResult::REFUSAL_PAYMENT_NOT_ALLOCATABLE);
            }

            $remaining = $this->arPaymentBalance->unallocated($locked);
            $amounts = [];

            $invoices = ArInvoice::query()
                ->where('property_id', $locked->property_id)
                ->where('company_id', $locked->company_id)
                ->where('type', ArInvoiceType::Invoice)
                ->where('status', ArInvoiceStatus::Open)
                ->orderBy('due_on')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($invoices as $invoice) {
                if (bccomp($remaining, '0', 2) <= 0) {
                    break;
                }

                $balance = $this->arInvoiceBalance->forInvoice($invoice);

                if (bccomp($balance, '0', 2) <= 0) {
                    continue;
                }

                $amount = bccomp($balance, $remaining, 2) <= 0 ? $balance : $remaining;
                $amounts[$invoice->id] = $amount;
                $remaining = bcsub($remaining, $amount, 2);
            }

            return $amounts === [] ? ArPaymentResult::done($locked) : ($this->allocateArPayment)($actor, $locked, $amounts);
        });
    }
}
