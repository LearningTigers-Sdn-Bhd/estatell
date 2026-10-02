<?php

namespace App\Data\Reservations;

use App\Models\UnitAddOn;
use Brick\Math\BigDecimal;
use Spatie\LaravelData\Data;

/**
 * One add-on row. Expects `chargeItem` to be loaded. `total` is `quantity × unit_amount`.
 */
class ReservationAddOnData extends Data
{
    public function __construct(
        public int $id,
        public int $unitId,
        public string $unitLabel,
        public string $chargeItem,
        public int $quantity,
        public ?string $serviceDate,
        public string $unitAmount,
        public string $total,
    ) {}

    public static function fromModel(UnitAddOn $addOn, string $unitLabel): self
    {
        return new self(
            id: $addOn->id,
            unitId: $addOn->reservation_unit_id,
            unitLabel: $unitLabel,
            chargeItem: $addOn->chargeItem->name,
            quantity: $addOn->quantity,
            serviceDate: $addOn->service_date?->toDateString(),
            unitAmount: $addOn->unit_amount,
            total: BigDecimal::of($addOn->unit_amount)->multipliedBy($addOn->quantity)->toScale(2)->__toString(),
        );
    }
}
