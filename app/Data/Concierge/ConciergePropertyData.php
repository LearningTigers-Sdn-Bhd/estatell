<?php

namespace App\Data\Concierge;

use Spatie\LaravelData\Data;

/**
 * What a guest reads about the property itself: where it is and how to reach it. `mapsUrl` is the owner's map link, or a
 * link made from the coordinates, or null.
 */
class ConciergePropertyData extends Data
{
    /**
     * @param  list<string>  $addressLines
     */
    public function __construct(
        public string $name,
        public array $addressLines,
        public ?string $phone,
        public ?string $email,
        public ?string $mapsUrl,
        public string $checkInTime,
        public string $checkOutTime,
    ) {}
}
