<?php

namespace App\Actions\CityLedger;

use App\Data\CityLedger\NewArPaymentData;
use App\Enums\ArInvoiceStatus;
use App\Enums\PaymentProofStatus;
use App\Models\ArAllocation;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\PaymentProof;
use App\Models\User;
use App\Services\CityLedger\ArInvoiceBalance;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class AcceptCompanyPaymentSlip
{
    public const string REFUSAL_NOT_SUBMITTED = 'slip_not_submitted';

    public function __construct(
        private RecordArPayment $recordArPayment,
        private AllocateArPayment $allocateArPayment,
        private AutoAllocateArPayment $autoAllocateArPayment,
        private ArInvoiceBalance $arInvoiceBalance,
    ) {}

    /**
     * Record the payment a company's slip shows as a city ledger payment with the slip's reference. A slip for one
     * invoice (`ar_invoice_id`) fills that invoice first while it is Open; the rest goes to the oldest open invoices, and
     * what is left stays as credit. The slip row is locked first and the key `slip:{id}` makes a repeat record nothing new. A
     * refusal of `RecordArPayment` passes through and nothing is saved. Logs `payment_slip.accepted` on the company.
     */
    public function __invoke(User $actor, PaymentProof $slip, int $paymentMethodId, string $amount): ArPaymentResult
    {
        return DB::transaction(function () use ($actor, $slip, $paymentMethodId, $amount): ArPaymentResult {
            $locked = PaymentProof::query()->lockForUpdate()->findOrFail($slip->id);

            if ($locked->status !== PaymentProofStatus::Submitted || $locked->company === null) {
                return ArPaymentResult::refused(self::REFUSAL_NOT_SUBMITTED);
            }

            $result = ($this->recordArPayment)($actor, $locked->company, new NewArPaymentData(
                paymentMethodId: $paymentMethodId,
                amount: $amount,
                idempotencyKey: 'slip:'.$locked->id,
                reference: $locked->reference,
            ));

            if ($result->isRefused() || $result->payment === null) {
                return $result;
            }

            $allocations = $result->replayed ? [] : $this->allocate($actor, $locked, $result->payment);

            $locked->forceFill([
                'status' => PaymentProofStatus::Accepted,
                'ar_payment_id' => $result->payment->id,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ])->save();

            activity()
                ->performedOn($locked->company)
                ->causedBy($actor)
                ->event('payment_slip.accepted')
                ->withProperties(['property_id' => $locked->property_id, 'payment_proof_id' => $locked->id, 'ar_payment_id' => $result->payment->id, 'amount' => $amount])
                ->log('Payment slip accepted');

            return $result->replayed ? $result : ArPaymentResult::done($result->payment, $allocations);
        });
    }

    /**
     * Fill the slip's invoice with as much as it still owes, then the oldest open invoices with the rest.
     *
     * @return list<ArAllocation>
     */
    private function allocate(User $actor, PaymentProof $slip, ArPayment $payment): array
    {
        $allocations = [];
        $invoice = $slip->ar_invoice_id === null ? null : ArInvoice::query()->find($slip->ar_invoice_id);

        if ($invoice !== null && $invoice->status === ArInvoiceStatus::Open) {
            $amount = BigDecimal::min($this->arInvoiceBalance->forInvoice($invoice), $payment->amount);

            if ($amount->isPositive()) {
                $allocations = ($this->allocateArPayment)($actor, $payment, [$invoice->id => (string) $amount->toScale(2)])->allocations;
            }
        }

        return [...$allocations, ...($this->autoAllocateArPayment)($actor, $payment)->allocations];
    }
}
