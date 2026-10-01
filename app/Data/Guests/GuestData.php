<?php

namespace App\Data\Guests;

use App\Models\Guest;
use App\Models\Tag;
use App\Services\Guests\CountryOptions;
use Spatie\LaravelData\Data;

/**
 * A guest profile for the detail page and the form. Expects the `tags` relation to be loaded.
 */
class GuestData extends Data
{
    /**
     * @param  list<TagData>  $tags
     */
    public function __construct(
        public int $id,
        public string $fullName,
        public ?string $email,
        public ?string $phone,
        public ?string $nationalityCode,
        public ?string $nationalityName,
        public ?string $dateOfBirth,
        public ?string $gender,
        public ?string $genderLabel,
        public ?string $preferredLocale,
        public ?string $preferredLocaleLabel,
        public ?string $addressLine1,
        public ?string $addressLine2,
        public ?string $city,
        public ?string $state,
        public ?string $postalCode,
        public ?string $countryCode,
        public ?string $countryName,
        public bool $isVip,
        public ?string $blacklistedAt,
        public ?string $blacklistReason,
        public array $tags,
        public string $createdAt,
    ) {}

    public static function fromModel(Guest $guest): self
    {
        $countries = app(CountryOptions::class);

        return new self(
            id: $guest->id,
            fullName: $guest->full_name,
            email: $guest->email,
            phone: $guest->phone,
            nationalityCode: $guest->nationality_code,
            nationalityName: $countries->name($guest->nationality_code),
            dateOfBirth: $guest->date_of_birth?->toDateString(),
            gender: $guest->gender?->value,
            genderLabel: $guest->gender?->label(),
            preferredLocale: $guest->preferred_locale?->value,
            preferredLocaleLabel: $guest->preferred_locale?->label(),
            addressLine1: $guest->address_line1,
            addressLine2: $guest->address_line2,
            city: $guest->city,
            state: $guest->state,
            postalCode: $guest->postal_code,
            countryCode: $guest->country_code,
            countryName: $countries->name($guest->country_code),
            isVip: $guest->is_vip,
            blacklistedAt: $guest->blacklisted_at?->toDateString(),
            blacklistReason: $guest->blacklist_reason,
            tags: $guest->tags->sortBy(fn (Tag $tag): string => mb_strtolower($tag->name))->map(fn (Tag $tag): TagData => TagData::fromModel($tag))->values()->all(),
            createdAt: $guest->created_at->toDateString(),
        );
    }
}
