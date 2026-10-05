<?php

namespace App\Actions\Registration;

use App\Enums\RegistrationCardStatus;
use App\Models\RegistrationCard;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class VoidRegistrationCard
{
    /**
     * Void a Draft or Signed card. The signed text and snapshot stay for the record.
     *
     * Returns false, without writing, when the card is already void.
     */
    public function __invoke(User $actor, RegistrationCard $card, string $reason): bool
    {
        return DB::transaction(function () use ($actor, $card, $reason): bool {
            $card = RegistrationCard::query()->lockForUpdate()->findOrFail($card->id);

            if (! $card->status->canTransitionTo(RegistrationCardStatus::Void)) {
                return false;
            }

            $wasSigned = $card->status === RegistrationCardStatus::Signed;

            $card->forceFill([
                'status' => RegistrationCardStatus::Void,
                'void_reason' => $reason,
                'voided_at' => now(),
            ])->save();

            activity()
                ->performedOn($card)
                ->causedBy($actor)
                ->event('registration_card.voided')
                ->withProperties(['property_id' => $card->property_id, 'was_signed' => $wasSigned, 'reason' => $reason])
                ->log('Registration card voided');

            return true;
        });
    }
}
