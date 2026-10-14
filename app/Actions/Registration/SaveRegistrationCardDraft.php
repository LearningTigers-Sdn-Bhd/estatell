<?php

namespace App\Actions\Registration;

use App\Actions\Guests\AddIdentityDocument;
use App\Data\Registration\RegistrationCardDraftData;
use App\Enums\RegistrationCardStatus;
use App\Models\Guest;
use App\Models\RegistrationCard;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveRegistrationCardDraft
{
    public function __construct(private AddIdentityDocument $addIdentityDocument) {}

    /**
     * Staff pick the ID document and type the vehicle plate and remarks before the card goes to the guest.
     * A new document is added to the guest's profile.
     *
     * Returns false, without writing, when the card is not a draft or the guest's profile is closed.
     */
    public function __invoke(User $actor, RegistrationCard $card, RegistrationCardDraftData $data): bool
    {
        return DB::transaction(function () use ($actor, $card, $data): bool {
            $card = RegistrationCard::query()->lockForUpdate()->with('unitGuest')->findOrFail($card->id);

            if ($card->status !== RegistrationCardStatus::Draft) {
                return false;
            }

            $documentId = $data->identityDocumentId;

            if ($data->identity !== null) {
                $document = ($this->addIdentityDocument)($actor, Guest::query()->findOrFail($card->unitGuest->guest_id), $data->identity);

                if ($document === null) {
                    return false;
                }

                $documentId = $document->id;
            }

            $card->forceFill(['identity_document_id' => $documentId, 'vehicle_plate' => $data->vehiclePlate, 'remarks' => $data->remarks])->save();

            return true;
        });
    }
}
