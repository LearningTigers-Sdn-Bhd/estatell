<?php

namespace App\Data\Guests;

use App\Enums\GuestMatchReason;
use App\Models\Guest;
use Spatie\LaravelData\Data;

/**
 * A guest that may be the same person as the details entered, with the reasons.
 */
class GuestMatchData extends Data
{
    /**
     * @param  list<string>  $reasons  Labels, for example "Same phone".
     * @param  bool  $isStrong  False when the only reason is the name.
     */
    public function __construct(
        public int $id,
        public string $fullName,
        public ?string $email,
        public ?string $phone,
        public bool $isVip,
        public bool $isBlacklisted,
        public array $reasons,
        public bool $isStrong,
        public GuestProfileData $profile,
    ) {}

    /**
     * @param  array{guest: Guest, reasons: list<GuestMatchReason>}  $match
     */
    public static function fromMatch(array $match): self
    {
        $guest = $match['guest'];

        return new self(
            id: $guest->id,
            fullName: $guest->full_name,
            email: $guest->email,
            phone: $guest->phone,
            isVip: $guest->is_vip,
            isBlacklisted: $guest->blacklisted_at !== null,
            reasons: array_map(fn (GuestMatchReason $reason): string => $reason->label(), $match['reasons']),
            isStrong: array_any($match['reasons'], fn (GuestMatchReason $reason): bool => $reason->isStrong()),
            profile: GuestProfileData::fromModel($guest),
        );
    }
}
