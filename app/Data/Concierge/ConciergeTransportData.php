<?php

namespace App\Data\Concierge;

use Spatie\LaravelData\Data;

/**
 * How a guest gets to the property, as the guest reads it. The distances are already worded, such as "25 km · 35 min".
 */
class ConciergeTransportData extends Data
{
    public function __construct(
        public ?string $directions,
        public ?string $airport,
        public ?string $station,
        public ?string $cityCentre,
        public ?string $nearestTransitStop,
        public bool $airportTransferOffered,
        public ?string $airportTransferPrice,
        public ?string $airportTransferLead,
        public ?string $pickupPoint,
        public ?string $shuttleSchedule,
        public ?string $parkingNotes,
        public ?string $notes,
    ) {}
}
