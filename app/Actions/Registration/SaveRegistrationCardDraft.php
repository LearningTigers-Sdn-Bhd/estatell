<?php

namespace App\Actions\Registration;

use App\Actions\Guests\AddIdentityDocument;
use App\Enums\RegistrationCardStatus;
use App\Models\Guest;
use App\Models\RegistrationCard;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveRegistrationCardDraft
{
    public function __construct(private AddIdentityDocument $addIdentityDocument) {}

    /**
     * Staff pick the ID document and type the vehicle plate before the card goes to the guest.
     * A new document is added to the guest's profile.
     *
     * Returns false, without writing, when the card is not a draft or the guest's profile is closed.
     *
     * @param  array{identity: array{type: string, number: string, issuing_country_code: string|null, expires_on: string|null}|null, identity_document_id: int|null, vehicle_plate: string|null}  $input
     */
    public function __invoke(User $actor, RegistrationCard $card, array $input): bool
    {
        return DB::transaction(function () use ($actor, $card, $input): bool {
            $card = RegistrationCard::query()->lockForUpdate()->with('unitGuest')->findOrFail($card->id);

            if ($card->status !== RegistrationCardStatus::Draft) {
                return false;
            }

            $documentId = $input['identity_document_id'];

            if ($input['identity'] !== null) {
                $document = ($this->addIdentityDocument)($actor, Guest::query()->findOrFail($card->unitGuest->guest_id), $input['identity']);

                if ($document === null) {
                    return false;
                }

                $documentId = $document->id;
            }

            $card->forceFill(['identity_document_id' => $documentId, 'vehicle_plate' => $input['vehicle_plate']])->save();

            return true;
        });
    }
}
