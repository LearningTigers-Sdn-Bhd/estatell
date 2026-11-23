<?php

namespace App\Data\Facilities;

use App\Enums\FacilityBookedBy;
use App\Models\Facility;
use Spatie\LaravelData\Data;

/**
 * One facility row in the Facilities section of the Timeline View. `capacity` is its How many. `single` is true for a
 * facility booked by item with 1 item (a hall): its cells name the booking instead of counting. `unit` names what is
 * counted, for the cell title ("people", "items").
 */
class FacilityTimelineRowData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public int $capacity,
        public bool $single,
        public string $unit,
    ) {}

    public static function fromModel(Facility $facility): self
    {
        return new self(
            id: $facility->id,
            name: $facility->name,
            capacity: $facility->capacity,
            single: $facility->booked_by === FacilityBookedBy::Item && $facility->capacity === 1,
            unit: $facility->booked_by->unit($facility->capacity),
        );
    }
}
