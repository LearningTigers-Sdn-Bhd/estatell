<?php

namespace App\Services\Reservations;

use App\Data\Reservations\GroupNightPricePreviewData;
use App\Data\Reservations\GroupNightPricePreviewLineData;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitNight;
use Brick\Math\BigDecimal;

/**
 * What the group Change price does to every night of each picked unit, read only. Each unit goes through
 * `NightPriceChangePreview::preview()` with all its nights, so the numbers match the one-unit Dialog and the save.
 * See docs/12-reservations/09-change-night-price.md#change-the-price-of-a-group.
 */
class GroupNightPricePreview
{
    public function __construct(
        private NightPriceChangePreview $nightPriceChangePreview,
        private ReservationRoomBox $roomBox,
    ) {}

    /**
     * @param  list<int>  $unitIds
     */
    public function preview(Reservation $reservation, array $unitIds, ?string $price): GroupNightPricePreviewData
    {
        $units = $reservation->units()->whereIn('id', $unitIds)->with(['roomType', 'nights'])->orderBy('id')->get();
        $assigned = $this->roomBox->assignmentsForUnits(array_values(array_map(intval(...), $units->modelKeys())));
        $sum = BigDecimal::zero();
        $complete = $price !== null && $units->isNotEmpty();
        $hadDiscount = false;
        $lines = [];

        foreach ($units as $unit) {
            /** @var ReservationUnit $unit */
            $nightIds = array_values($unit->nights->map(fn (UnitNight $night): int => $night->id)->all());
            $preview = $this->nightPriceChangePreview->preview($unit, $nightIds, $price);
            $hadDiscount = $hadDiscount || $preview->hadDiscount;

            if ($preview->difference === null) {
                $complete = false;
            } else {
                $sum = $sum->plus($preview->difference);
            }

            $lines[] = new GroupNightPricePreviewLineData(
                unitId: $unit->id,
                label: $unit->roomType->name.' · '.($assigned[$unit->id] ?? __('Not assigned')),
                nights: count($nightIds),
                was: $preview->was,
                wasHigh: $preview->wasHigh,
                charged: count(array_filter($preview->nights, fn ($night): bool => $night->charged)),
                difference: $preview->difference,
                error: $nightIds === [] ? __('This unit has no nights to price.') : $preview->error,
            );
        }

        return new GroupNightPricePreviewData(
            lines: $lines,
            difference: $complete ? (string) $sum->toScale(2) : null,
            hadDiscount: $hadDiscount,
        );
    }
}
