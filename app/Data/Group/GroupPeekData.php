<?php

namespace App\Data\Group;

use App\Data\Property\CurrencyData;
use App\Data\Shared\BadgeData;
use Spatie\LaravelData\Data;

/**
 * A reservation at a glance for the group side panel (Reservations, Timeline and Front desk reports): the booker,
 * the stay, the rooms with their guests, the remarks and the money, with the links into the property where it is
 * changed. Guest names, phone, email and the guest profile link need `guests.view` at that property; without it they
 * are null. `remarks` holds the remark of each kind that has one, by its label.
 * See docs/03-staff/05-group-mode.md#reservation-panel.
 */
class GroupPeekData extends Data
{
    /**
     * @param  list<GroupPeekUnitData>  $units
     * @param  array<string, string>  $remarks
     */
    public function __construct(
        public int $id,
        public string $reference,
        public string $propertyCode,
        public string $propertyName,
        public BadgeData $status,
        public ?string $booker,
        public ?string $phone,
        public ?string $email,
        public ?string $nationality,
        public ?string $company,
        public ?string $source,
        public ?string $groupName,
        public string $arrival,
        public string $departure,
        public int $nights,
        public array $units,
        public array $remarks,
        public string $total,
        public string $paid,
        public string $balance,
        public ?CurrencyData $currency,
        public string $reservationUrl,
        public ?string $guestUrl,
    ) {}
}
