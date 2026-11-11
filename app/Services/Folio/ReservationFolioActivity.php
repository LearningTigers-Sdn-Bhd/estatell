<?php

namespace App\Services\Folio;

use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Finds reservations with a folio line posted since a moment. Lines are never edited, so a new line is how a charge,
 * a reversal, a discount or a refund shows. Commission fees uses it to re-check only changed bookings.
 * See docs/30-commission-fees/02-commission-rules.md#due-date.
 */
class ReservationFolioActivity
{
    /**
     * @param  Builder<Reservation>  $reservations
     * @return Builder<Reservation>
     */
    public function postedSince(Builder $reservations, CarbonImmutable $since): Builder
    {
        return $reservations->whereExists(fn (QueryBuilder $query) => $query
            ->from('folio_lines')
            ->join('folios', 'folios.id', '=', 'folio_lines.folio_id')
            ->whereColumn('folios.reservation_id', 'reservations.id')
            ->where('folio_lines.created_at', '>=', $since));
    }
}
