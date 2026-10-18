<?php

namespace App\Services\Reservations;

use App\Models\BookingSource;
use App\Models\Property;
use App\Models\Reservation;

/**
 * Reads how many reservations use each booking source, whatever their status. Reads only; the Actions decide what to
 * do with the counts. See docs/12-reservations/11-booking-sources.md.
 */
class BookingSourceUsage
{
    /**
     * Reservations per source, keyed by booking source id. A source no reservation uses is not in the list.
     *
     * @return array<int, int>
     */
    public function counts(Property $property): array
    {
        return Reservation::query()
            ->where('property_id', $property->id)
            ->selectRaw('booking_source_id, count(*) as reservations')
            ->groupBy('booking_source_id')
            ->get()
            ->mapWithKeys(fn (Reservation $row): array => [(int) $row->booking_source_id => (int) $row->getAttribute('reservations')])
            ->all();
    }

    public function count(BookingSource $source): int
    {
        return Reservation::query()->where('booking_source_id', $source->id)->count();
    }

    public function isUsed(BookingSource $source): bool
    {
        return Reservation::query()->where('booking_source_id', $source->id)->exists();
    }
}
