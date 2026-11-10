<?php

namespace App\Services\Dashboard;

use App\Data\Dashboard\NewBookingsTileData;
use App\Data\Dashboard\TodayBookingRowData;
use App\Data\Dashboard\TodayBookingsData;
use App\Models\Property;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bookings created on the business date, read in the property's time zone. Feeds the Today's New Booking tile and
 * Today's Booking List. It reads only.
 */
class TodayBookings
{
    private const LIMIT = 10;

    public function tile(Property $property, CarbonImmutable $date): NewBookingsTileData
    {
        $query = $this->query($property, $date);

        return new NewBookingsTileData(
            (clone $query)->count(),
            (int) (clone $query)->withCount('units')->get()->sum('units_count'),
        );
    }

    public function forDate(Property $property, CarbonImmutable $date): TodayBookingsData
    {
        $timeZone = $this->timeZone($property);
        $query = $this->query($property, $date);
        $total = (clone $query)->count();

        $rows = $query->with('booker')->withCount('units')->withMin('units', 'arrival_date')->withMax('units', 'departure_date')
            ->latest('created_at')->limit(self::LIMIT)->get()
            ->map(fn (Reservation $reservation): TodayBookingRowData => new TodayBookingRowData(
                id: $reservation->id,
                guest: $reservation->booker->full_name ?? $reservation->reference,
                reference: $reservation->reference,
                created: $reservation->created_at->setTimezone($timeZone)->format('H:i'),
                rooms: (int) $reservation->units_count,
                arrival: $reservation->getAttribute('units_min_arrival_date'),
                departure: $reservation->getAttribute('units_max_departure_date'),
                url: route('reservations.show', ['property' => $property->code, 'reservation' => $reservation->id], false),
            ))->all();

        return new TodayBookingsData(array_values($rows), $total, $date->toDateString());
    }

    /** @return Builder<Reservation> */
    private function query(Property $property, CarbonImmutable $date): Builder
    {
        $start = CarbonImmutable::parse($date->toDateString(), $this->timeZone($property))->startOfDay();

        return Reservation::query()->where('property_id', $property->id)->whereBetween('created_at', [
            $start->setTimezone(config('app.timezone')),
            $start->endOfDay()->setTimezone(config('app.timezone')),
        ]);
    }

    private function timeZone(Property $property): string
    {
        return $property->loadMissing('settings')->settings->time_zone ?? config('app.timezone');
    }
}
