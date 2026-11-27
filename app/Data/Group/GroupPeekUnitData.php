<?php

namespace App\Data\Group;

use App\Data\Shared\BadgeData;
use Spatie\LaravelData\Data;

/**
 * One room or bed of a reservation in the group peek: where, its stay, who is in it (`GuestParty`, null without
 * `guests.view`) and its road transfers in one line each (`TransferLabel`).
 */
class GroupPeekUnitData extends Data
{
    public function __construct(
        public int $id,
        public string $label,
        public BadgeData $status,
        public string $arrival,
        public string $departure,
        public int $adults,
        public int $children,
        public ?string $party,
        public ?string $transferIn,
        public ?string $transferOut,
    ) {}
}
