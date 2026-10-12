<?php

namespace App\Data\Reservations;

use App\Models\UnitAddOn;
use App\Services\Reservations\AddOnLabel;
use Brick\Math\BigDecimal;
use Spatie\LaravelData\Data;

/**
 * One add-on row. Expects `chargeItem`, `ageGroup` and `folioLine` to be loaded. `total` is `quantity × unit_amount`. A posted
 * add-on has `posted`, its folio (`folioId`, `folioLabel`) and the posting date. An add-on that is not posted and that
 * routing sends away from the unit's own folio has `routedTo`, the label of the folio it will post to.
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
        public bool $posted = false,
        public ?int $folioId = null,
        public ?string $folioLabel = null,
        public ?string $postedOn = null,
        public ?string $routedTo = null,
    ) {}

    public static function fromModel(UnitAddOn $addOn, string $unitLabel, ?string $folioLabel = null, ?string $routedTo = null): self
    {
        return new self(
            id: $addOn->id,
            unitId: $addOn->reservation_unit_id,
            unitLabel: $unitLabel,
            chargeItem: AddOnLabel::for($addOn),
            quantity: $addOn->quantity,
            serviceDate: $addOn->service_date?->toDateString(),
            unitAmount: $addOn->unit_amount,
            total: BigDecimal::of($addOn->unit_amount)->multipliedBy($addOn->quantity)->toScale(2)->__toString(),
            posted: $addOn->folio_line_id !== null,
            folioId: $addOn->folioLine?->folio_id,
            folioLabel: $folioLabel,
            postedOn: $addOn->folioLine?->posting_date?->toDateString(),
            routedTo: $routedTo,
        );
    }
}
