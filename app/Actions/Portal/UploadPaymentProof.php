<?php

namespace App\Actions\Portal;

use App\Enums\ReservationStatus;
use App\Models\PaymentProof;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class UploadPaymentProof
{
    /**
     * Keep a payment proof an agent uploads for one of its bookings: the file, the amount and the bank reference. A
     * proof is not a payment: the schedule stays due until staff accept it. Returns null for a cancelled or completed
     * booking and writes nothing. The caller found the reservation through the agent's company. Logs
     * `payment_proof.uploaded` on the reservation.
     */
    public function __invoke(User $actor, Reservation $reservation, UploadedFile $file, string $amount, ?string $reference): ?PaymentProof
    {
        if (in_array($reservation->status, [ReservationStatus::Cancelled, ReservationStatus::Completed], true)) {
            return null;
        }

        return DB::transaction(function () use ($actor, $reservation, $file, $amount, $reference): PaymentProof {
            $proof = new PaymentProof(['amount' => $amount, 'reference' => filled($reference) ? trim((string) $reference) : null]);
            $proof->forceFill(['property_id' => $reservation->property_id, 'reservation_id' => $reservation->id, 'uploaded_by' => $actor->id])->save();
            $proof->addMedia($file)->toMediaCollection(PaymentProof::FILE);

            activity()
                ->performedOn($reservation)
                ->causedBy($actor)
                ->event('payment_proof.uploaded')
                ->withProperties(['property_id' => $reservation->property_id, 'payment_proof_id' => $proof->id, 'amount' => $proof->amount, 'reference' => $proof->reference])
                ->log('Payment proof uploaded');

            return $proof;
        });
    }
}
