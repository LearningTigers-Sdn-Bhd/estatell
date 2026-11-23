<?php

namespace App\Services\Facilities;

use App\Data\Facilities\FacilityDayCellData;
use App\Data\Facilities\FacilityDayLineData;
use App\Data\Facilities\FacilityTimelineRowData;
use App\Enums\FacilityBookingStatus;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The Facilities section of the Timeline View: the facility rows, a cell for each facility and date with a live
 * booking, and the bookings of one facility on one date for the day peek. The stay view only draws what this sends,
 * so it never reads `facility_bookings` itself. Cancelled bookings are left out. See
 * docs/33-facilities/05-timeline-and-report.md.
 */
class FacilityTimeline
{
    /**
     * The facilities in their order: the available ones, and any other with a live booking in the window.
     *
     * @return list<FacilityTimelineRowData>
     */
    public function rows(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $booked = $this->bookings($property, $from, $to)->pluck('facility_id')->unique();

        return array_values(Facility::query()
            ->where('property_id', $property->id)
            ->where(fn ($query) => $query->where('active', true)->orWhereIn('id', $booked))
            ->ordered()
            ->get()
            ->map(FacilityTimelineRowData::fromModel(...))
            ->all());
    }

    /**
     * One cell for each facility and date that has a live booking. A date with none sends nothing: the grid shows it
     * as 0 booked.
     *
     * @return list<FacilityDayCellData>
     */
    public function cells(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_values($this->bookings($property, $from, $to)
            ->groupBy(fn (FacilityBooking $booking): string => $booking->facility_id.'|'.$booking->booking_date->toDateString())
            ->map(function (Collection $day): FacilityDayCellData {
                $first = $day->first();
                $booked = $this->peak($day->where('status', FacilityBookingStatus::Confirmed));

                return new FacilityDayCellData(
                    facilityId: $first->facility_id,
                    date: $first->booking_date->toDateString(),
                    booked: $booked,
                    full: $booked >= $first->facility->capacity,
                    bookings: $day->count(),
                    tentative: $day->where('status', FacilityBookingStatus::Tentative)->count(),
                    label: $day->count() === 1 ? FacilityDayLineData::nameOf($first) : null,
                );
            })
            ->all());
    }

    /**
     * The live bookings of one facility on one date, by start time, for the day peek.
     *
     * @return list<FacilityDayLineData>
     */
    public function day(Facility $facility, CarbonImmutable $date): array
    {
        return array_values(FacilityBooking::query()
            ->where('property_id', $facility->property_id)
            ->where('facility_id', $facility->id)
            ->whereDate('booking_date', $date)
            ->live()
            ->with(['facility', 'event:id,name', 'reservation:id,reference,booker_guest_id', 'reservation.booker:id,full_name'])
            ->ordered()
            ->get()
            ->map(FacilityDayLineData::fromModel(...))
            ->all());
    }

    /**
     * @return Collection<int, FacilityBooking>
     */
    private function bookings(Property $property, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return FacilityBooking::query()
            ->where('property_id', $property->id)
            ->whereBetween('booking_date', [$from->toDateString(), $to->toDateString()])
            ->live()
            ->with(['facility:id,capacity', 'event:id,name', 'reservation:id,reference,booker_guest_id', 'reservation.booker:id,full_name'])
            ->ordered()
            ->get();
    }

    /**
     * The most the bookings take at one moment. Bookings that touch (one ends as the next starts) do not overlap.
     *
     * @param  Collection<int, FacilityBooking>  $bookings
     */
    private function peak(Collection $bookings): int
    {
        return (int) $bookings
            ->map(fn (FacilityBooking $start): int => (int) $bookings
                ->filter(fn (FacilityBooking $booking): bool => $booking->starts_at <= $start->starts_at && $booking->ends_at > $start->starts_at)
                ->sum('quantity'))
            ->max();
    }
}
