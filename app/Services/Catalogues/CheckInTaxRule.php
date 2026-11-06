<?php

namespace App\Services\Catalogues;

use App\Models\Property;
use App\Models\Tax;
use Illuminate\Database\Eloquent\Collection;

/**
 * The one rule of which tax is paid at the desk: a tax flagged **Collect at check-in**, on a room night. A flagged tax
 * on an add-on or a plan inclusion is not, because check-in collects only room-night taxes. Check-in and the booking
 * engine's online total share it, so a guest never pays a tax twice or not at all.
 * See docs/29-booking-engine/03-checkout-and-hold.md#taxes-in-the-online-total.
 */
class CheckInTaxRule
{
    /**
     * @return Collection<int, Tax>
     */
    public function flaggedTaxes(Property $property): Collection
    {
        return Tax::query()->where('property_id', $property->id)->where('enabled', true)->where('collect_at_check_in', true)->orderBy('id')->get();
    }

    /**
     * The tax id when the row is a room-night tax of a flagged tax, otherwise null. A room-night tax row is keyed
     * `night:{id}:tax:{tax id}`. A fixed night (`night:{id}:fix:{n}`) and an inclusion (`night:{id}:incl:{id}`) do not match.
     *
     * @param  list<int>  $flaggedTaxIds
     */
    public function deskTaxId(string $rowKey, array $flaggedTaxIds): ?int
    {
        if (preg_match('/^night:\d+:tax:(\d+)$/', $rowKey, $matches) !== 1 || ! in_array((int) $matches[1], $flaggedTaxIds, true)) {
            return null;
        }

        return (int) $matches[1];
    }
}
