<?php

namespace App\Services\Reservations;

use App\Enums\PricingBasis;
use App\Enums\StayType;
use App\Models\ReservationUnit;
use Illuminate\Support\Collection;

/**
 * The length label of a day use unit: "Day use · 4h", never "0 nights". An overnight unit has none, because its length
 * is counted in nights. A reservation of day use units with different hours reads "Day use". It reads the loaded
 * `dayUse` relation of each unit, and loads it when it is missing for a day use unit.
 * See docs/12-reservations/10-day-use.md.
 */
class StayLabel
{
    /**
     * The plan name of a unit. A day use unit on a plan that is not a Day use plan was sold with an entered price.
     */
    public function planForUnit(ReservationUnit $unit): string
    {
        if ($unit->stay_type === StayType::DayUse && $unit->ratePlan->pricing_basis !== PricingBasis::DayUse) {
            return __('Entered price');
        }

        return $unit->ratePlan->name;
    }

    /**
     * "Day use · 4h" for a day use unit, otherwise null.
     */
    public function forUnit(ReservationUnit $unit): ?string
    {
        if ($unit->stay_type !== StayType::DayUse) {
            return null;
        }

        $unit->loadMissing('dayUse');

        return self::dayUse($unit->dayUse?->hours);
    }

    /**
     * The label of a reservation: the unit label when every unit is day use for the same hours, "Day use" when the hours
     * differ, null when the units are overnight.
     *
     * @param  iterable<ReservationUnit>  $units
     */
    public function forUnits(iterable $units): ?string
    {
        $dayUse = Collection::make($units)->filter(fn (ReservationUnit $unit): bool => $unit->stay_type === StayType::DayUse);

        if ($dayUse->isEmpty()) {
            return null;
        }

        $hours = $dayUse->map(fn (ReservationUnit $unit): ?int => $unit->loadMissing('dayUse')->dayUse?->hours)->unique();

        return self::dayUse($hours->count() === 1 ? $hours->first() : null);
    }

    /**
     * "Day use · 10:00–14:00" for a day use unit, otherwise null. The reports show it as the stay of the unit.
     */
    public function hoursForUnit(ReservationUnit $unit): ?string
    {
        if ($unit->stay_type !== StayType::DayUse) {
            return null;
        }

        $block = $unit->loadMissing('dayUse')->dayUse;

        if ($block === null) {
            return self::dayUse(null);
        }

        return __('Day use · :from–:to', ['from' => substr($block->starts_at, 0, 5), 'to' => substr($block->ends_at, 0, 5)]);
    }

    public static function dayUse(?int $hours): string
    {
        return $hours === null ? __('Day use') : __('Day use · :hours', ['hours' => $hours.'h']);
    }
}
