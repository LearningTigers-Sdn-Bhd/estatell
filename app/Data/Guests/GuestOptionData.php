<?php

namespace App\Data\Guests;

use App\Models\Guest;
use Spatie\LaravelData\Data;

/**
 * One result of the guest search picker.
 */
class GuestOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $fullName,
        public ?string $phone,
        public ?string $email,
        public bool $isVip,
        public bool $isBlacklisted,
    ) {}

    public static function fromModel(Guest $guest): self
    {
        return new self($guest->id, $guest->full_name, $guest->phone, $guest->email, $guest->is_vip, $guest->blacklisted_at !== null);
    }
}
