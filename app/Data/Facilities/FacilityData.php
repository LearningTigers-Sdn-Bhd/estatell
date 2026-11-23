<?php

namespace App\Data\Facilities;

use App\Enums\FacilityCapacityUnit;
use App\Enums\FacilityRateBasis;
use App\Enums\FacilitySharing;
use App\Models\Facility;
use Spatie\LaravelData\Data;

/**
 * One row of the Facilities settings table and the facility Sheet. `amount` is the charge item's list price, or null
 * for "Charge by hand". `sessionCount` is set on Shared facilities only.
 */
class FacilityData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public FacilitySharing $sharing,
        public int $capacity,
        public FacilityCapacityUnit $capacityUnit,
        public int $chargeItemId,
        public ?string $amount,
        public FacilityRateBasis $rateBasis,
        public bool $active,
        public int $sessionCount,
    ) {}

    /**
     * Load `chargeItem` and count `sessions` first.
     */
    public static function fromModel(Facility $facility): self
    {
        return new self(
            id: $facility->id,
            name: $facility->name,
            sharing: $facility->sharing,
            capacity: $facility->capacity,
            capacityUnit: $facility->capacity_unit,
            chargeItemId: $facility->charge_item_id,
            amount: $facility->chargeItem->default_amount,
            rateBasis: $facility->rate_basis,
            active: $facility->active,
            sessionCount: (int) ($facility->sessions_count ?? 0),
        );
    }
}
