<?php

namespace App\Data\Portal;

use App\Models\UnitAddOn;
use Brick\Math\BigDecimal;
use Spatie\LaravelData\Data;

/**
 * An add-on of a unit as the company sees it: the item, the quantity and the amounts.
 */
class PortalBookingAddOnData extends Data
{
    public function __construct(
        public string $name,
        public int $quantity,
        public string $unitAmount,
        public string $total,
        public ?string $serviceDate,
    ) {}

    /**
     * Load the add-on's `chargeItem` first.
     */
    public static function fromAddOn(UnitAddOn $addOn): self
    {
        return new self(
            name: $addOn->chargeItem->name,
            quantity: $addOn->quantity,
            unitAmount: $addOn->unit_amount,
            total: (string) BigDecimal::of($addOn->unit_amount)->multipliedBy($addOn->quantity)->toScale(2),
            serviceDate: $addOn->service_date?->toDateString(),
        );
    }
}
