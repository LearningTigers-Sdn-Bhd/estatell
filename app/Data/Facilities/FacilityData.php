<?php

namespace App\Data\Facilities;

use App\Enums\FacilityCapacityUnit;
use App\Enums\FacilityRateBasis;
use App\Enums\FacilitySharing;
use App\Models\Facility;
use Spatie\LaravelData\Data;

/**
 * A facility on the Facilities list and its own page. `amount` is the charge item's list price, or null for "Charge by
 * hand". `code` and `taxIds` are the charge item's. `sessionCount` counts the sessions of a Shared facility.
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
        public string $code,
        /** @var list<int> */
        public array $taxIds,
        /** @var list<string> */
        public array $taxNames,
        public string $sharingLabel,
        public string $capacityUnitLabel,
        public string $rateBasisLabel,
    ) {}

    /**
     * Load `chargeItem.transactionCode.applicableTaxes` and count `sessions` first.
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
            code: $facility->chargeItem->transactionCode->code,
            taxIds: array_values(array_map('intval', $facility->chargeItem->transactionCode->applicableTaxes->modelKeys())),
            taxNames: array_values($facility->chargeItem->transactionCode->applicableTaxes->sortBy('name')->pluck('name')->all()),
            sharingLabel: $facility->sharing->label(),
            capacityUnitLabel: $facility->capacity_unit->label(),
            rateBasisLabel: $facility->rate_basis->label(),
        );
    }
}
