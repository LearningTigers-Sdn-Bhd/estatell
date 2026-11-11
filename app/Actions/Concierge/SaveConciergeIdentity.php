<?php

namespace App\Actions\Concierge;

use App\Actions\Guests\AddIdentityDocument;
use App\Actions\Guests\SetGuestDetails;
use App\Actions\Guests\UpdateIdentityDocument;
use App\Actions\Kiosk\KioskIdentityResult;
use App\Data\Guests\IdentityDocumentInputData;
use App\Data\Kiosk\KioskIdentityData;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\ReservationUnit;
use App\Services\Kiosk\KioskStays;
use App\Services\Registration\RegistrationCardFieldRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class SaveConciergeIdentity
{
    public function __construct(
        private KioskStays $stays,
        private AddIdentityDocument $addDocument,
        private UpdateIdentityDocument $updateDocument,
        private SetGuestDetails $setGuestDetails,
        private RegistrationCardFieldRules $fieldRules,
    ) {}

    /**
     * Save the ID the guest typed as the stay guest's identity document, with the photos when given, and fill the
     * guest's name, nationality, date of birth and gender where they are empty (a value staff entered stays). A
     * document with the same type and number is updated, not added twice. Refused when the stay has no open guest.
     * `nationalityRequired` is true when an enabled foreign-only tax needs a nationality and the guest still has none.
     * The log names the fields and the document type, never the values.
     */
    public function __invoke(ReservationUnit $stay, KioskIdentityData $data, ?UploadedFile $front = null, ?UploadedFile $back = null): KioskIdentityResult
    {
        $stay->loadMissing('property');

        return DB::transaction(function () use ($stay, $data, $front, $back): KioskIdentityResult {
            $guest = $this->stays->guest($stay);

            if ($guest === null || ! $this->saveDocument($guest, $data->document, $front, $back)) {
                return KioskIdentityResult::refused();
            }

            $filled = ($this->setGuestDetails)($guest, [
                'full_name' => $data->fullName,
                'nationality_code' => $data->nationalityCode,
                'date_of_birth' => $data->dateOfBirth,
                'gender' => $data->gender,
            ], keepExisting: true, context: ['channel' => 'concierge']);

            if (! $filled) {
                return KioskIdentityResult::refused();
            }

            activity()
                ->performedOn($guest)
                ->event('concierge.identity_received')
                ->withProperties(['property_id' => $guest->property_id, 'type' => $data->document->type->value])
                ->log('ID entered on the concierge page');

            $guest->refresh();

            return KioskIdentityResult::accepted(
                blank($guest->nationality_code) && $this->fieldRules->nationalityIsNeededForTax($stay->property),
            );
        });
    }

    private function saveDocument(Guest $guest, IdentityDocumentInputData $input, ?UploadedFile $front, ?UploadedFile $back): bool
    {
        $existing = GuestIdentityDocument::query()
            ->where('guest_id', $guest->id)
            ->where('type', $input->type)
            ->whereBlind('number', GuestIdentityDocument::NUMBER_INDEX, $input->number)
            ->first();

        if ($existing === null) {
            return ($this->addDocument)(null, $guest, $input, $front, $back) !== null;
        }

        return ($this->updateDocument)(null, $existing, new IdentityDocumentInputData(
            type: $input->type,
            number: $input->number,
            issuingCountryCode: $input->issuingCountryCode ?? $existing->issuing_country_code,
            expiresOn: $input->expiresOn ?? $existing->expires_on?->toDateString(),
        ));
    }
}
