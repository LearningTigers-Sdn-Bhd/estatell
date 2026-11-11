<?php

namespace App\Actions\Portal;

use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Portal\PortalHowToPay;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class UploadPaymentProof
{
    public function __construct(private PortalHowToPay $howToPay, private UploadCompanyPaymentSlip $uploadSlip) {}

    /**
     * Keep a payment proof an agent uploads for one of its bookings: the file, Paid into (checked by the request), the
     * amount and the bank reference. A proof is not a payment: the schedule stays due until staff accept it. While a
     * folio the company pays is open it is a proof on the reservation (logs `payment_proof.uploaded` on it). Once the
     * booking is invoiced it is a company slip for that invoice (`UploadCompanyPaymentSlip`). Returns null when neither
     * applies (cancelled, or completed with nothing open) and writes nothing. The caller found the reservation through
     * the agent's company.
     */
    public function __invoke(User $actor, Company $company, Reservation $reservation, UploadedFile $file, string $amount, ?string $reference, ?int $bankAccountId = null): ?PaymentProof
    {
        if (! $this->howToPay->acceptsProof($reservation)) {
            $invoice = $this->howToPay->openInvoice($company, $reservation);

            return $invoice === null ? null : ($this->uploadSlip)($actor, $company, $file, $amount, $reference, $bankAccountId, $invoice->id);
        }

        return DB::transaction(function () use ($actor, $reservation, $file, $amount, $reference, $bankAccountId): PaymentProof {
            $proof = new PaymentProof(['amount' => $amount, 'reference' => filled($reference) ? trim((string) $reference) : null, 'bank_account_id' => $bankAccountId]);
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
