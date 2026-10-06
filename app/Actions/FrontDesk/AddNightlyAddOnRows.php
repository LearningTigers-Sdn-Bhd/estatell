<?php

namespace App\Actions\FrontDesk;

use App\Enums\ChargingUnit;
use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;

class AddNightlyAddOnRows
{
    /**
     * Give each nightly add-on of a unit (PerNight and PerGuestNight) a row for the given new nights, copying its
     * charge item, quantity and amount from its latest row. Nights before the business date get no row, because they
     * would never post, and a night that already has a row for the item is skipped. `ChangeUnitDates` calls this
     * inside its transaction, after the new nights exist. See docs/12-reservations/04-add-ons.md.
     *
     * @param  list<CarbonImmutable>  $newNights
     * @return int the number of rows created
     */
    public function __invoke(ReservationUnit $unit, array $newNights, CarbonImmutable $businessDate): int
    {
        $dates = array_values(array_filter($newNights, fn (CarbonImmutable $night): bool => $night->gte($businessDate)));

        if ($dates === []) {
            return 0;
        }

        $templates = $unit->addOns()
            ->whereHas('chargeItem', fn ($item) => $item->whereIn('charging_unit', [ChargingUnit::PerNight->value, ChargingUnit::PerGuestNight->value]))
            ->orderBy('id')
            ->get()
            ->keyBy('charge_item_id');
        $created = 0;

        foreach ($templates as $template) {
            $existing = $unit->addOns()->where('charge_item_id', $template->charge_item_id)->pluck('service_date')
                ->map(fn (mixed $date): string => $date->toDateString())->all();

            foreach ($dates as $date) {
                if (in_array($date->toDateString(), $existing, true)) {
                    continue;
                }

                $unit->addOns()->create([
                    'charge_item_id' => $template->charge_item_id,
                    'quantity' => $template->quantity,
                    'unit_amount' => $template->unit_amount,
                    'service_date' => $date->toDateString(),
                ]);
                $created++;
            }
        }

        return $created;
    }
}
