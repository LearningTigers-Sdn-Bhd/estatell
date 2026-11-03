<?php

namespace App\Actions\Payments;

use App\Enums\PaymentProofStatus;
use App\Models\PaymentProof;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RejectPaymentProof
{
    /**
     * Turn down an agent's payment proof with a reason the agent sees in the portal. Returns false, writing nothing,
     * when the proof was already accepted or rejected. Logs `payment_proof.rejected`.
     */
    public function __invoke(User $actor, PaymentProof $proof, string $reason): bool
    {
        return DB::transaction(function () use ($actor, $proof, $reason): bool {
            $locked = PaymentProof::query()->lockForUpdate()->findOrFail($proof->id);

            if (! $locked->status->canTransitionTo(PaymentProofStatus::Rejected) || trim($reason) === '') {
                return false;
            }

            $locked->forceFill([
                'status' => PaymentProofStatus::Rejected,
                'reject_reason' => mb_substr(trim($reason), 0, 500),
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ])->save();

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('payment_proof.rejected')
                ->withProperties(['property_id' => $locked->property_id, 'payment_proof_id' => $locked->id, 'reason' => $locked->reject_reason])
                ->log('Payment proof rejected');

            return true;
        });
    }
}
