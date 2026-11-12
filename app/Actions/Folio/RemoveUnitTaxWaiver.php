<?php

namespace App\Actions\Folio;

use App\Models\ReservationUnit;
use App\Models\ReservationUnitTaxWaiver;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RemoveUnitTaxWaiver
{
    /**
     * Charge a waived tax again for one unit. Only nights not posted yet get it back: the lines reversed when it was
     * waived stay reversed, and staff use Add tax on a posted night. The unit row is locked first. A tax with no
     * waiver on the unit (a repeated click) is `waiver_not_found`, which writes and logs nothing. Permissions are
     * checked by the route. See docs/06-catalogues/03-taxes.md#tax-waivers.
     */
    public function __invoke(User $actor, ReservationUnit $unit, int $taxId): TaxWaiverResult
    {
        return DB::transaction(function () use ($actor, $unit, $taxId): TaxWaiverResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);
            $waiver = $locked->taxWaivers()->where('tax_id', $taxId)->first();

            if (! $waiver instanceof ReservationUnitTaxWaiver) {
                return TaxWaiverResult::refused(TaxWaiverResult::REFUSAL_WAIVER_NOT_FOUND);
            }

            $waiver->delete();

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('reservation.unit_tax_waiver_removed')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->id,
                    'tax_id' => $taxId,
                    'reason' => $waiver->reason->value,
                    'paid_to' => $waiver->paid_to,
                    'reference' => $waiver->reference,
                ])
                ->log('Unit tax waiver removed');

            return TaxWaiverResult::done();
        });
    }
}
