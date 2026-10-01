<?php

namespace App\Data\Guests;

use App\Models\Guest;
use App\Models\Tag;
use Spatie\LaravelData\Data;

/**
 * One row of the guest list. Expects the `tags` relation to be loaded.
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
        public ?string $nationalityCode,
        public bool $isVip,
        public bool $isBlacklisted,
        public bool $isAnonymized,
        public array $tags,
        public string $createdAt,
    ) {}

    public static function fromModel(Guest $guest): self
    {
        return new self(
            id: $guest->id,
            fullName: $guest->full_name,
            email: $guest->email,
            phone: $guest->phone,
            nationalityCode: $guest->nationality_code,
            isVip: $guest->is_vip,
            isBlacklisted: $guest->blacklisted_at !== null,
            isAnonymized: $guest->anonymized_at !== null,
            tags: $guest->tags->map(fn (Tag $tag): string => $tag->name)->sortBy(fn (string $name): string => mb_strtolower($name))->values()->all(),
            createdAt: $guest->created_at->toDateString(),
        );
    }
}
