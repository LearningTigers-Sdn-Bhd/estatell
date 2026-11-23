<?php

namespace App\Actions\Facilities;

use App\Actions\Folio\PostingResult;
use App\Enums\FacilityBookingStatus;
use App\Features\Facilities;
use App\Models\FacilityBooking;
use App\Models\Property;
use App\Services\NightAudit\CurrentBusinessDate;
use Carbon\CarbonImmutable;
use Laravel\Pennant\Feature;

class PostFacilityCharges
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private PostFacilityBooking $postFacilityBooking,
    ) {}

    /**
     * The night audit step body: post every Tentative or Confirmed booking of the property dated on or before the
     * business date that has a price and no `facility:{id}` line yet, through `PostFacilityBooking`. An earlier date
     * catches up a booking that a skipped audit missed or that staff entered after its date; its line posts on the
     * business date with the booking date as `service_date`. A Tentative booking is refused
     * (`facility_booking_tentative`), so the step turns it into an exception. Each booking is its own transaction, so
     * one refusal never stops the others. Nothing runs while the `Facilities` feature is off or `$date` is not the
     * business date. The results are keyed by booking id. See docs/33-facilities/04-facility-bookings.md#posting.
     *
     * @return array<int, PostingResult>
     */
    public function __invoke(Property $property, CarbonImmutable $date): array
    {
        $businessDate = $this->currentBusinessDate->date($property);

        if (! Feature::for($property)->active(Facilities::class) || $businessDate === null || ! $businessDate->isSameDay($date)) {
            return [];
        }

        $bookings = FacilityBooking::query()
            ->where('property_id', $property->id)
            ->whereDate('booking_date', '<=', $date->toDateString())
            ->whereIn('status', [FacilityBookingStatus::Tentative->value, FacilityBookingStatus::Confirmed->value])
            ->whereNotNull('unit_amount')
            ->whereNotExists(fn ($lines) => $lines->selectRaw('1')
                ->from('folio_lines')
                ->whereRaw("folio_lines.idempotency_key = 'facility:' || facility_bookings.id"))
            ->orderBy('id')
            ->get();
        $results = [];

        foreach ($bookings as $booking) {
            $results[$booking->id] = ($this->postFacilityBooking)(null, $booking);
        }

        return $results;
    }
}
