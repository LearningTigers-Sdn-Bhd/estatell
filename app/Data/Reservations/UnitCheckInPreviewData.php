<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What the Check in form shows when it opens: tonight's room, the registration card status, the checks the user may
 * override, the Nationality field when it is missing and Collect now (only for a user with `payment.record`).
 */
class UnitCheckInPreviewData extends Data
{
    /**
     * @param  list<array{value: string, label: string}>  $countries  Only when `needsNationality`.
     */
    public function __construct(
        public ?string $room,
        public int $cardsMissing,
        public bool $cardRequired,
        public bool $canCheckInWithoutCard,
        public bool $needsNationality,
        public array $countries,
        public bool $creditExceeded,
        public bool $canOverrideCredit,
        public ?CheckInCollectionData $collection,
        public ?string $error,
    ) {}

    public static function refused(string $error): self
    {
        return new self(null, 0, false, false, false, [], false, false, null, $error);
    }
}
