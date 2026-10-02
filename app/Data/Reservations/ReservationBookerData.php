<?php

namespace App\Data\Reservations;

use App\Models\Guest;
use Spatie\LaravelData\Data;

/**
 * The booker in the summary card. `id`, `phone` and `email` are null for staff without `guests.view`.
 */
class ReservationBookerData extends Data
{
    public function __construct(
        public ?int $id,
        public string $name,
        public bool $isVip,
        public bool $isBlacklisted,
        public ?string $phone,
        public ?string $email,
    ) {}

    public static function fromModel(Guest $guest, bool $canViewGuest): self
    {
        return new self(
            id: $canViewGuest ? $guest->id : null,
            name: $guest->full_name,
            isVip: $guest->is_vip,
            isBlacklisted: $guest->blacklisted_at !== null,
            phone: $canViewGuest ? $guest->phone : null,
            email: $canViewGuest ? $guest->email : null,
        );
    }
}
