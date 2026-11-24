<?php

namespace App\Services\Pos;

use App\Enums\BusinessDateStatus;
use App\Models\BusinessDate;
use App\Models\Property;

/**
 * The business date every journal-affecting POS write posts on, and the lock that keeps it steady. Call it inside the
 * Action's transaction, first: it takes a shared lock on the property row, so any number of tills run at once while the
 * Night Audit, which locks the same row exclusively, waits for them or they wait for it. It answers `business_date_auditing`
 * while the audit is closing the date (tills try again in a second or two), and `no_business_date` before the property
 * has one. `CurrentBusinessDate::date()` would also return the Auditing date, so it is never used for POS writes.
 * Lock order for POS writes: properties (shared), business dates, reservation units by id, orders, shifts.
 */
class OpenPosBusinessDate
{
    /**
     * @return BusinessDate|string The Open business date, or a refusal code.
     */
    public function lock(Property $property): BusinessDate|string
    {
        Property::query()->whereKey($property->id)->sharedLock()->first();

        $current = $property->businessDates()->current()->first();

        return match (true) {
            $current === null => 'no_business_date',
            $current->status !== BusinessDateStatus::Open => 'business_date_auditing',
            default => $current,
        };
    }
}
