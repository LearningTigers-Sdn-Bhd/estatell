<?php

namespace App\Services\Catalogues;

use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Services\Reservations\UnitNationality;
use Illuminate\Support\Collection;

/**
 * The one rule of which taxes a reservation unit is not charged: the taxes it has a waiver for (someone else
 * collected them, or the guest has a receipt). `NightChargeQuote` and `DayUseChargeQuote` drop them, so the night
 * audit, the Scheduled rows, Collect now and Change price all agree. It reads the unit's `taxWaivers` relation, so a
 * caller that quotes many units eager-loads it. A unit that is not saved yet (a booking engine quote) has none.
 * See docs/06-catalogues/03-taxes.md#tax-waivers.
 */
class TaxWaivers
{
    public function __construct(private UnitNationality $unitNationality) {}

    /**
     * True when the tax can be charged to the unit, so a waiver means something: a foreign-only tax does not apply to
     * a guest of the property's own country. A unit with no nationality yet may still be foreign, so it applies.
     */
    public function appliesTo(Tax $tax, ReservationUnit $unit): bool
    {
        if (! $tax->foreign_guests_only) {
            return true;
        }

        $nationality = $this->unitNationality->for($unit);

        return blank($nationality) || UnitNationality::isForeign($nationality, $unit->property()->value('country_code'));
    }

    /**
     * @return list<int>
     */
    public function waivedTaxIds(ReservationUnit $unit): array
    {
        if (! $unit->exists) {
            return [];
        }

        return array_values(array_map(intval(...), $unit->loadMissing('taxWaivers')->taxWaivers->pluck('tax_id')->all()));
    }

    /**
     * The taxes less the ones waived for the unit.
     *
     * @param  Collection<int, Tax>  $taxes
     * @return Collection<int, Tax>
     */
    public function without(Collection $taxes, ReservationUnit $unit): Collection
    {
        $waived = $this->waivedTaxIds($unit);

        return $waived === [] ? $taxes : $taxes->reject(fn (Tax $tax): bool => in_array($tax->id, $waived, true))->values();
    }
}
