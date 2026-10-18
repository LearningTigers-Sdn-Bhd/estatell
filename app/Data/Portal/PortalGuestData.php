<?php

namespace App\Data\Portal;

use App\Models\Guest;
use Spatie\LaravelData\Data;

/**
 * A guest of a booking, for the Edit guests form. The ID number is never sent: `idType` says which kind of ID is on
 * file, and a new number replaces it.
 */
class PortalGuestData extends Data
{
    public function __construct(
        public int $guestId,
        public string $fullName,
        public ?string $phone,
        public ?string $idType,
        public ?string $idTypeLabel,
    ) {}

    /**
     * Load the guest's `documents` (id, guest_id, type) first.
     */
    public static function fromGuest(Guest $guest): self
    {
        $document = $guest->documents->sortBy('id')->first();

        return new self($guest->id, $guest->full_name, $guest->phone, $document?->type->value, $document?->type->label());
    }
}
