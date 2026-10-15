<?php

namespace App\Services\Onboarding;

use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\Folio;
use App\Models\Property;
use App\Models\Reservation;

/**
 * Which companies of a property something already refers to: a reservation, a folio, an invoice or a payment. Those
 * companies cannot be removed, because the database restricts the delete.
 */
class CompanyReferences
{
    /**
     * @return list<int>
     */
    public function referencedIds(Property $property): array
    {
        $ids = $property->companies()->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        return collect([
            Reservation::query()->whereIn('company_id', $ids)->distinct()->pluck('company_id'),
            Folio::query()->whereIn('payer_company_id', $ids)->distinct()->pluck('payer_company_id'),
            ArInvoice::query()->whereIn('company_id', $ids)->distinct()->pluck('company_id'),
            ArPayment::query()->whereIn('company_id', $ids)->distinct()->pluck('company_id'),
        ])->flatten()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
    }
}
