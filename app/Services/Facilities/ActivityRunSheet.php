<?php

namespace App\Services\Facilities;

use App\Data\Facilities\ActivitySlotData;
use App\Enums\FacilityBookedBy;
use App\Enums\FacilityBookingStatus;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\FacilitySession;
use App\Models\FacilitySlotCrew;
use App\Models\Property;
use Carbon\CarbonImmutable;

/**
 * The Activities run sheet of one date: every session of the property's available Person facilities (activities), and
 * every other time a Tentative or Confirmed booking of one of them takes, by facility order and start time. Each slot
 * carries the places booked and its guide and boat. Three queries for any number of slots.
 * See docs/33-facilities/06-guides-and-boats.md#activities-run-sheet.
 */
class ActivityRunSheet
{
    /**
     * @return list<ActivitySlotData>
     */
    public function slots(Property $property, CarbonImmutable $date): array
    {
        $facilities = Facility::query()
            ->where('property_id', $property->id)
            ->where('booked_by', FacilityBookedBy::Person)
            ->with(['sessions' => fn ($query) => $query->orderBy('starts_at')])
            ->ordered()
            ->get();
        $bookings = FacilityBooking::query()
            ->whereIn('facility_id', $facilities->modelKeys())
            ->whereDate('booking_date', $date->toDateString())
            ->whereIn('status', [FacilityBookingStatus::Tentative, FacilityBookingStatus::Confirmed])
            ->get(['facility_id', 'starts_at', 'ends_at', 'quantity']);
        $crews = $this->crews($property, $date);

        $slots = [];

        foreach ($facilities as $facility) {
            /** @var array<string, array{starts: string, ends: string, booked: int, bookings: int, session: bool}> $times */
            $times = [];

            if ($facility->active) {
                foreach ($facility->sessions as $session) {
                    /** @var FacilitySession $session */
                    $times[$session->starts_at.'|'.$session->ends_at] = ['starts' => $session->starts_at, 'ends' => $session->ends_at, 'booked' => 0, 'bookings' => 0, 'session' => true];
                }
            }

            foreach ($bookings->where('facility_id', $facility->id) as $booking) {
                $key = $booking->starts_at.'|'.$booking->ends_at;
                $time = $times[$key] ?? ['starts' => $booking->starts_at, 'ends' => $booking->ends_at, 'booked' => 0, 'bookings' => 0, 'session' => false];
                $times[$key] = [...$time, 'booked' => $time['booked'] + $booking->quantity, 'bookings' => $time['bookings'] + 1];
            }

            uasort($times, fn (array $a, array $b): int => [$a['starts'], $a['ends']] <=> [$b['starts'], $b['ends']]);

            foreach ($times as $key => $time) {
                $crew = $crews[$date->toDateString().'|'.$facility->id.'|'.$key] ?? null;
                $slots[] = new ActivitySlotData(
                    facilityId: $facility->id,
                    facility: $facility->name,
                    startsAt: substr($time['starts'], 0, 5),
                    endsAt: substr($time['ends'], 0, 5),
                    booked: $time['booked'],
                    bookings: $time['bookings'],
                    capacity: $facility->capacity,
                    unit: $facility->booked_by->unit($facility->capacity),
                    isSession: $time['session'],
                    guideId: $crew?->facility_guide_id,
                    boatId: $crew?->facility_boat_id,
                    boatSeats: $crew?->boat?->seats,
                );
            }
        }

        return $slots;
    }

    /**
     * The guide and boat of every slot from `$date` to `$until` (default the same date), keyed
     * `{Y-m-d}|{facility id}|{starts_at}|{ends_at}` with `H:i:s` times. The Facility bookings report names them in its
     * section headings.
     *
     * @return array<string, FacilitySlotCrew>
     */
    public function crews(Property $property, CarbonImmutable $date, ?CarbonImmutable $until = null): array
    {
        return FacilitySlotCrew::query()
            ->where('property_id', $property->id)
            ->whereBetween('slot_date', [$date->toDateString(), ($until ?? $date)->toDateString()])
            ->with(['guide', 'boat'])
            ->get()
            ->keyBy(fn (FacilitySlotCrew $crew): string => $crew->slot_date->toDateString().'|'.$crew->facility_id.'|'.$crew->starts_at.'|'.$crew->ends_at)
            ->all();
    }
}
