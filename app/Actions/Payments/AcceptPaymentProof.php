<?php

namespace App\Actions\Payments;

use App\Data\Payments\NewPaymentData;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\PaymentProofStatus;
use App\Enums\PaymentPurpose;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\PaymentProof;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AcceptPaymentProof
{
    public const string REFUSAL_NOT_SUBMITTED = 'proof_not_submitted';

    public const string REFUSAL_NO_FOLIO = 'proof_no_folio';

    public function __construct(private RecordPayment $recordPayment) {}

    /**
     * Record the payment an agent's proof shows, as an Advance with the proof's reference, on the reservation's Master
     * folio (the company's bill), else on its first live room. The proof is then Accepted and points to the payment.
     * The proof row is locked first and the key `proof:{id}` makes a repeat record nothing new. A refusal of
     * `RecordPayment` passes through and nothing is saved. Logs `payment_proof.accepted`.
     */
    public function __invoke(User $actor, PaymentProof $proof, int $paymentMethodId, string $amount): PaymentResult
    {
        return DB::transaction(function () use ($actor, $proof, $paymentMethodId, $amount): PaymentResult {
            $locked = PaymentProof::query()->lockForUpdate()->findOrFail($proof->id);

            if ($locked->status !== PaymentProofStatus::Submitted) {
                return PaymentResult::refused(self::REFUSAL_NOT_SUBMITTED);
            }

            $reservation = $locked->reservation;
            $master = Folio::query()
                ->where('reservation_id', $reservation->id)
                ->where('type', FolioType::Master)
                ->where('status', FolioStatus::Open)
                ->first();
            $unit = $master === null
                ? $reservation->units()->whereIn('status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory()))->orderBy('id')->first()
                : null;

            if ($master === null && $unit === null) {
                return PaymentResult::refused(self::REFUSAL_NO_FOLIO);
            }

            $result = ($this->recordPayment)($actor, $reservation, $master, $unit, new NewPaymentData(
                paymentMethodId: $paymentMethodId,
                purpose: PaymentPurpose::Advance,
                amount: $amount,
                reference: $locked->reference,
                idempotencyKey: 'proof:'.$locked->id,
            ));

            if ($result->isRefused() || $result->payment === null) {
                return $result;
            }

            $locked->forceFill([
                'status' => PaymentProofStatus::Accepted,
                'payment_id' => $result->payment->id,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ])->save();

            activity()
                ->performedOn($reservation)
                ->causedBy($actor)
                ->event('payment_proof.accepted')
                ->withProperties(['property_id' => $reservation->property_id, 'payment_proof_id' => $locked->id, 'payment_id' => $result->payment->id, 'amount' => $amount])
                ->log('Payment proof accepted');

            return $result;
        });
    }
}
