<?php

namespace App\Data\Facilities;

use App\Enums\FacilitySharing;
use App\Models\Facility;
use Spatie\LaravelData\Data;

/**
 * A facility in the Book facility Sheet's select. `listPrice` is null for "Charge by hand"; `sessions` are the fixed
 * times of a Shared facility, empty when it takes any time.
 */
class FacilityOptionData extends Data
{
    /**
     * @param  list<FacilitySessionData>  $sessions
     */
    public function __construct(
        public int $id,
        public string $name,
        public FacilitySharing $sharing,
        public int $capacity,
        public string $capacityUnitLabel,
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
            sharing: $facility->sharing,
            capacity: $facility->capacity,
            capacityUnitLabel: $facility->capacity_unit->label(),
            rateBasisLabel: $facility->rate_basis->label(),
            listPrice: $facility->chargeItem->default_amount,
            sessions: array_values($facility->sessions->map(fn ($session): FacilitySessionData => FacilitySessionData::fromModel($session))->all()),
        );
    }
}
