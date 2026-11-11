<?php

namespace App\Actions\CityLedger;

use App\Data\CityLedger\NewArPaymentData;
use App\Enums\PaymentProofStatus;
use App\Models\PaymentProof;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AcceptCompanyPaymentSlip
{
    public const string REFUSAL_NOT_SUBMITTED = 'slip_not_submitted';

    public function __construct(private RecordArPayment $recordArPayment) {}

    /**
     * Record the payment a company's slip shows as a city ledger payment with the slip's reference, matched to its
     * oldest open invoices. The slip row is locked first and the key `slip:{id}` makes a repeat record nothing new. A
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
            ), true);

            if ($result->isRefused() || $result->payment === null) {
                return $result;
            }

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

            return $result;
        });
    }
}
