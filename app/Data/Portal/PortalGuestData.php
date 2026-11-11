<?php

namespace App\Data\Portal;

use App\Models\Guest;
use Spatie\LaravelData\Data;

/**
 * A guest of a booking, for the Guests tab: the name, phone, ID type and the profile the company may edit (email,
 * personal details and address). The ID number is never sent: `idType` says which kind of ID is on file, and a new
 * number replaces it.
 */
class PortalGuestData extends Data
{
    public function __construct(
        public int $guestId,
        public string $fullName,
        public ?string $phone,
        public ?string $idType,
        public ?string $idTypeLabel,
        public ?string $nationalityCode = null,
        public ?string $email = null,
        public ?string $dateOfBirth = null,
        public ?string $gender = null,
        public ?string $preferredLocale = null,
        public ?string $addressLine1 = null,
        public ?string $addressLine2 = null,
        public ?string $city = null,
        public ?string $state = null,
        public ?string $postalCode = null,
        public ?string $countryCode = null,
    ) {}

    /**
     * Load the guest's `documents` (id, guest_id, type) first.
     */
    public static function fromGuest(Guest $guest): self
    {
        $document = $guest->documents->sortBy('id')->first();

        return new self(
            guestId: $guest->id,
            fullName: $guest->full_name,
            phone: $guest->phone,
            idType: $document?->type->value,
            idTypeLabel: $document?->type->label(),
            nationalityCode: $guest->nationality_code,
            email: $guest->email,
            dateOfBirth: $guest->date_of_birth?->toDateString(),
            gender: $guest->gender?->value,
            preferredLocale: $guest->preferred_locale?->value,
            addressLine1: $guest->address_line1,
            addressLine2: $guest->address_line2,
            city: $guest->city,
            state: $guest->state,
            postalCode: $guest->postal_code,
            countryCode: $guest->country_code,
        );
    }
}
