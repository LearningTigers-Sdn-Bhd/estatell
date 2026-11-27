<?php

namespace App\Data\Facilities;

use App\Enums\FacilityBookedBy;
use App\Enums\FacilityRateBasis;
use App\Models\Facility;
use Spatie\LaravelData\Data;

/**
 * A facility on the Facilities list and its own page. `amount` is the charge item's list price, or null for "Charge by
 * hand". `code` and `taxIds` are the charge item's. `sessionCount` counts its sessions. `unitLabel` is
 * what `capacity` counts ("1 item", "20 people").
 */
class FacilityData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public FacilityBookedBy $bookedBy,
        public int $capacity,
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
        public string $bookedByLabel,
        public string $unitLabel,
        public string $rateBasisLabel,
        public ?string $privateAmount = null,
    ) {}

    /**
     * Load `chargeItem.transactionCode.applicableTaxes` and count `sessions` first.
     */
    public static function fromModel(Facility $facility): self
    {
        return new self(
            id: $facility->id,
            name: $facility->name,
            bookedBy: $facility->booked_by,
            capacity: $facility->capacity,
            chargeItemId: $facility->charge_item_id,
            amount: $facility->chargeItem->default_amount,
            rateBasis: $facility->rate_basis,
            active: $facility->active,
            sessionCount: (int) ($facility->sessions_count ?? 0),
            code: $facility->chargeItem->transactionCode->code,
            taxIds: array_values(array_map('intval', $facility->chargeItem->transactionCode->applicableTaxes->modelKeys())),
            taxNames: array_values($facility->chargeItem->transactionCode->applicableTaxes->sortBy('name')->pluck('name')->all()),
            bookedByLabel: $facility->booked_by->label(),
            unitLabel: $facility->booked_by->unit($facility->capacity),
            rateBasisLabel: $facility->rate_basis->label($facility->booked_by),
            privateAmount: $facility->private_amount,
        );
    }
}
