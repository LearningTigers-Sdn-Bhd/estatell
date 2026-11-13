<?php

namespace App\Actions\Folio;

use App\Enums\TaxWaiverReason;
use App\Enums\UnitAction;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\User;
use App\Services\Catalogues\TaxWaivers;
use Illuminate\Support\Facades\DB;

class WaiveGroupTax
{
    public function __construct(
        private WaiveUnitTax $waiveUnitTax,
        private TaxWaivers $taxWaivers,
    ) {}

    /**
     * Stop charging the chosen taxes for several units of one reservation at once (Taxes paid elsewhere in More). Each
     * unit goes through `WaiveUnitTax` with the taxes it does not have waived yet and that apply to its guest (a
     * foreign-only tax skips a guest of the property's own country); a unit with none left is skipped.
     * Every chosen unit must belong to the reservation and be Confirmed, Checked in or Checked out (`unit_not_waivable`),
     * and every tax must be the property's (`tax_not_found`). Nothing to waive at all is `already_waived`. A refusal of
     * any unit rolls the whole request back, so a refusal writes and logs nothing. Permissions are checked by the route.
     * See docs/06-catalogues/03-taxes.md#tax-waivers.
     *
     * @param  list<int>  $unitIds
     * @param  list<int>  $taxIds
     */
    public function __invoke(User $actor, Reservation $reservation, array $unitIds, array $taxIds, TaxWaiverReason $reason, ?string $paidTo, string $reference): GroupTaxWaiverResult
    {
        try {
            return DB::transaction(function () use ($actor, $reservation, $unitIds, $taxIds, $reason, $paidTo, $reference): GroupTaxWaiverResult {
                $unitIds = array_values(array_unique($unitIds));
                $taxIds = array_values(array_unique($taxIds));
                $units = ReservationUnit::query()
                    ->where('reservation_id', $reservation->id)
                    ->whereIn('id', $unitIds)
                    ->with('taxWaivers')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($unitIds === [] || $units->count() !== count($unitIds) || $units->contains(fn (ReservationUnit $unit): bool => ! UnitAction::WaiveTax->isOpenFor($unit->status))) {
                    return GroupTaxWaiverResult::refused(TaxWaiverResult::REFUSAL_UNIT_NOT_WAIVABLE);
                }

                $taxes = Tax::query()->where('property_id', $reservation->property_id)->whereIn('id', $taxIds)->get()->keyBy('id');

                if ($taxIds === [] || $taxes->count() !== count($taxIds)) {
                    return GroupTaxWaiverResult::refused(TaxWaiverResult::REFUSAL_TAX_NOT_FOUND);
                }

                $waived = 0;
                $reversed = 0;

                foreach ($units as $unit) {
                    $missing = array_values(array_filter(
                        array_diff($taxIds, $unit->taxWaivers->pluck('tax_id')->all()),
                        fn (int $taxId): bool => $this->taxWaivers->appliesTo($taxes->get($taxId), $unit),
                    ));

                    if ($missing === []) {
                        continue;
                    }

                    $result = ($this->waiveUnitTax)($actor, $unit, $missing, $reason, $paidTo, $reference);

                    if ($result->isRefused()) {
                        throw new TaxWaiverRefused((string) $result->refusal);
                    }

                    $waived++;
                    $reversed += $result->reversed;
                }

                if ($waived === 0) {
                    return GroupTaxWaiverResult::refused(TaxWaiverResult::REFUSAL_ALREADY_WAIVED);
                }

                return GroupTaxWaiverResult::done($waived, $units->count() - $waived, $reversed);
            });
        } catch (TaxWaiverRefused $refused) {
            return GroupTaxWaiverResult::refused($refused->getMessage());
        }
    }
}
