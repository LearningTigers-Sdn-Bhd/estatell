<?php

namespace App\Actions\Kiosk;

use App\Actions\Guests\AddIdentityDocument;
use App\Actions\Guests\SetGuestDetails;
use App\Actions\Guests\UpdateIdentityDocument;
use App\Actions\Webhooks\TriggerWebhook;
use App\Data\Guests\IdentityDocumentInputData;
use App\Data\Kiosk\KioskIdentityData;
use App\Enums\WebhookEvent;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\Kiosk;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Kiosk\KioskStays;
use App\Services\Registration\RegistrationCardFieldRules;
use Illuminate\Support\Facades\DB;

class SaveStayIdentity
{
    public function __construct(
        private KioskStays $stays,
        private AddIdentityDocument $addDocument,
        private UpdateIdentityDocument $updateDocument,
        private SetGuestDetails $setGuestDetails,
        private RegistrationCardFieldRules $fieldRules,
    ) {}

    /**
     * Save the ID a kiosk read as the stay guest's identity document, and fill the guest's name, nationality, date of
     * birth and gender where they are empty (a value staff entered stays). A document with the same type and number is
     * updated, not added twice; an expiry date the kiosk did not send is kept. Refused when the stay has no open guest
     * (merged or anonymized). `nationalityRequired` is true when an enabled foreign-only tax needs a nationality and the
     * guest still has none. The log names the fields and the document type, never the values.
     */
    public function __invoke(Kiosk $kiosk, ReservationUnit $stay, KioskIdentityData $data): KioskIdentityResult
    {
        $stay->loadMissing('property');

        return DB::transaction(function () use ($kiosk, $stay, $data): KioskIdentityResult {
            $guest = $this->stays->guest($stay);

            if ($guest === null) {
                return KioskIdentityResult::refused();
            }

            $context = ['kiosk_id' => $kiosk->id];

            if (! $this->saveDocument($guest, $data->document)) {
                return KioskIdentityResult::refused();
            }

            $before = $this->contact($guest);
            $filled = ($this->setGuestDetails)($guest, [
                'full_name' => $data->fullName,
                'nationality_code' => $data->nationalityCode,
                'date_of_birth' => $data->dateOfBirth,
                'gender' => $data->gender,
            ], keepExisting: true, context: $context);

            if (! $filled) {
                return KioskIdentityResult::refused();
            }

            activity()
                ->performedOn($guest)
                ->event('kiosk.identity_received')
                ->withProperties(['property_id' => $guest->property_id, 'type' => $data->document->type->value, ...$context])
                ->log('ID read at the kiosk');

            $guest->refresh();
            $this->announceBooker($stay, $guest, $before);

            return KioskIdentityResult::accepted(
                blank($guest->nationality_code) && $this->fieldRules->nationalityIsNeededForTax($stay->property),
            );
        });
    }

    /**
     * A walk-in is booked with an empty booker before the ID step, so `booking.created` carries no contact details.
     * When the ID step fills the booker's contact details, `booking.updated` sends them to booking listeners.
     *
     * @param  array<string, string|null>  $before
     */
    private function announceBooker(ReservationUnit $stay, Guest $guest, array $before): void
    {
        if ($this->contact($guest) === $before || $stay->reservation()->value('booker_guest_id') !== $guest->id) {
            return;
        }

        app(TriggerWebhook::class)(WebhookEvent::BookingUpdated, $stay->property_id, Reservation::class, $stay->reservation_id);
    }

    /**
     * @return array<string, string|null>
     */
    private function contact(Guest $guest): array
    {
        return $guest->only(['full_name', 'email', 'phone', 'country_code', 'nationality_code']);
    }

    private function saveDocument(Guest $guest, IdentityDocumentInputData $input): bool
    {
        $existing = GuestIdentityDocument::query()
            ->where('guest_id', $guest->id)
            ->where('type', $input->type)
            ->whereBlind('number', GuestIdentityDocument::NUMBER_INDEX, $input->number)
            ->first();

        if ($existing === null) {
            return ($this->addDocument)(null, $guest, $input) !== null;
        }

        return ($this->updateDocument)(null, $existing, new IdentityDocumentInputData(
            type: $input->type,
            number: $input->number,
            issuingCountryCode: $input->issuingCountryCode ?? $existing->issuing_country_code,
            expiresOn: $input->expiresOn ?? $existing->expires_on?->toDateString(),
        ));
    }
}
