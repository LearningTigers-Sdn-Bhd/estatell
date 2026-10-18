<?php

namespace App\Data\Portal;

use Spatie\LaravelData\Data;

/**
 * One upcoming arrival on the portal dashboard: a Confirmed booking of the company user's company at a Live property.
 */
class PortalArrivalData extends Data
{
    public function __construct(
        public int $reservationId,
        public string $propertyName,
        public string $propertyCode,
        public string $reference,
        public ?string $externalReference,
        public string $title,
        public string $arrivalDate,
    ) {}
}
