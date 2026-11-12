<?php

namespace App\Data\Guests;

use App\Models\Guest;
use App\Models\Tag;
use App\Services\Guests\CountryOptions;
use Spatie\LaravelData\Data;

/**
 * One row of the guest list. Expects the `tags` relation to be loaded and the stay figures selected by `GuestDirectory`
 * (`stays`, `spend`, `last_visit`, `in_house` and `latest_source`). A guest with no stays has 0 stays and no spend.
 */
class GuestRowData extends Data
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public int $id,
        public string $fullName,
        public ?string $email,
        public ?string $phone,
        public ?string $nationalityName,
        public bool $isVip,
        public bool $isBlacklisted,
        public bool $isAnonymized,
        public array $tags,
        public string $createdAt,
        public int $stays,
        public ?string $spend,
        public ?string $lastVisit,
        public bool $inHouse,
        public ?string $source,
    ) {}

    public static function fromModel(Guest $guest): self
    {
        return new self(
            id: $guest->id,
            fullName: $guest->full_name,
            email: $guest->email,
            phone: $guest->phone,
            nationalityName: app(CountryOptions::class)->name($guest->nationality_code),
            isVip: $guest->is_vip,
            isBlacklisted: $guest->blacklisted_at !== null,
            isAnonymized: $guest->anonymized_at !== null,
            tags: array_values($guest->tags->map(fn (Tag $tag): string => $tag->name)->sortBy(fn (string $name): string => mb_strtolower($name))->all()),
            createdAt: $guest->created_at->toDateString(),
            stays: (int) $guest->getAttribute('stays'),
            spend: $guest->getAttribute('spend') === null ? null : (string) $guest->getAttribute('spend'),
            lastVisit: $guest->getAttribute('last_visit') === null ? null : (string) $guest->getAttribute('last_visit'),
            inHouse: (bool) $guest->getAttribute('in_house'),
            source: $guest->getAttribute('latest_source') === null ? null : (string) $guest->getAttribute('latest_source'),
        );
    }
}
