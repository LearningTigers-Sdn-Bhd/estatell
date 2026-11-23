<?php

namespace App\Data\Facilities;

use App\Enums\FacilityBookedBy;
use App\Models\Facility;
use Spatie\LaravelData\Data;

/**
 * A facility in the Book facility Sheet's select. `listPrice` is null for "Charge by hand"; `sessions` are the fixed
 * times, empty when it takes any time. `capacityUnitLabel` is what a quantity counts ("items", "people");
 * `capacityLabel` describes the facility in the select ("Item · 3 items").
 */
class FacilityOptionData extends Data
{
    /**
     * @param  list<FacilitySessionData>  $sessions
     */
    public function __construct(
        public int $id,
        public string $name,
        public FacilityBookedBy $bookedBy,
        public int $capacity,
        public string $capacityUnitLabel,
        public string $capacityLabel,
        public string $rateBasisLabel,
        public ?string $listPrice,
        public array $sessions,
    ) {}

    /**
     * Load `chargeItem` and the ordered `sessions` first.
     */
    public static function fromModel(Facility $facility): self
    {
        return new self(
            id: $facility->id,
            name: $facility->name,
            bookedBy: $facility->booked_by,
            capacity: $facility->capacity,
            capacityUnitLabel: $facility->booked_by->unit(),
            capacityLabel: $facility->booked_by->label().' · '.$facility->capacity.' '.$facility->booked_by->unit($facility->capacity),
            rateBasisLabel: $facility->rate_basis->label($facility->booked_by),
            listPrice: $facility->chargeItem->default_amount,
            sessions: array_values($facility->sessions->map(fn ($session): FacilitySessionData => FacilitySessionData::fromModel($session))->all()),
        );
    }
}
