<?php

namespace App\Data\Concierge;

use Spatie\LaravelData\Data;

class ConciergeAmenityData extends Data
{
    public function __construct(
        public string $name,
        public string $category,
        public ?string $location = null,
        public ?string $openingHours = null,
        public ?string $feeInformation = null,
        public bool $reservationRequired = false,
        public ?string $reservationInstructions = null,
        public ?string $guestNotes = null,
    ) {}
}
