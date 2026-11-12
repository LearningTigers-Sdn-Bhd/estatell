<?php

namespace App\Services\Catalogues;

use App\Enums\TaxWaiverReason;
use App\Models\ReservationUnit;
use App\Models\ReservationUnitTaxWaiver;
use Illuminate\Support\Collection;

/**
 * The notes a customer document prints for the taxes waived on its units, so a reader (the guest, the accountant)
 * sees why a tax is missing: "Tourism tax paid by Trip.com (ref 1128152109837544)." or "Tourism tax paid by the
 * guest (receipt TTX-88231)." Units that share a waiver (the same tax, payer and reference) give one note. It reads
 * only. See docs/06-catalogues/03-taxes.md#tax-waivers.
 */
class TaxWaiverNotes
{
    /**
     * @param  Collection<int, ReservationUnit>  $units
     * @return list<string>
     */
    public function for(Collection $units): array
    {
        if ($units->isEmpty()) {
            return [];
        }

        return array_values(ReservationUnitTaxWaiver::query()
            ->whereIn('reservation_unit_id', $units->pluck('id')->all())
            ->with('tax:id,name')
            ->orderBy('tax_id')
            ->orderBy('id')
            ->get()
            ->map(fn (ReservationUnitTaxWaiver $waiver): string => $waiver->reason === TaxWaiverReason::PaidElsewhere
                ? __(':tax paid by :payer (ref :reference).', ['tax' => $waiver->tax->name, 'payer' => $waiver->paid_to, 'reference' => $waiver->reference])
                : __(':tax paid by the guest (receipt :reference).', ['tax' => $waiver->tax->name, 'reference' => $waiver->reference]))
            ->unique()
            ->all());
    }
}
