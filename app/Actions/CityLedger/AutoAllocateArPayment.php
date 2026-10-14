<?php

namespace App\Actions\CityLedger;

use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\User;
use App\Services\CityLedger\ArInvoiceBalance;
use App\Services\CityLedger\ArPaymentBalance;
use Brick\Math\BigDecimal;
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

            $remaining = BigDecimal::of($this->arPaymentBalance->unallocated($locked));
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
                if ($remaining->isNegativeOrZero()) {
                    break;
                }

                $balance = $this->arInvoiceBalance->forInvoice($invoice);

                if (BigDecimal::of($balance)->isNegativeOrZero()) {
                    continue;
                }

                $amount = BigDecimal::min($balance, $remaining);
                $amounts[$invoice->id] = (string) $amount->toScale(2);
                $remaining = $remaining->minus($amount);
            }

            return $amounts === [] ? ArPaymentResult::done($locked) : ($this->allocateArPayment)($actor, $locked, $amounts);
        });
    }
}
