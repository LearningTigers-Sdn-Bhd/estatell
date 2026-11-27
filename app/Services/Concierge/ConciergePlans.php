<?php

namespace App\Services\Concierge;

use App\Data\Concierge\ConciergePlanData;
use App\Enums\FacilityBookingStatus;
use App\Enums\TransferDirection;
use App\Enums\TransferMode;
use App\Features\Facilities;
use App\Features\Transfers;
use App\Models\FacilityBooking;
use App\Models\ReservationUnit;
use App\Models\UnitTransfer;
use Carbon\CarbonImmutable;
use Laravel\Pennant\Feature;

/**
 * What is booked for a guest's stay besides the room, for the Concierge page: the room's road transfers (while the
 * Transfers feature is on) and the booking's Tentative and Confirmed activities from `$from` on (while Facilities is
 * on), by date and time. The guest sees when and where to be, never who else is booked.
 * See docs/30-concierge/01-overview.md#your-plans.
 */
class ConciergePlans
{
    /**
     * @return list<ConciergePlanData>
     */
    public function forStay(ReservationUnit $stay, CarbonImmutable $from): array
    {
        $property = $stay->property;
        $plans = [];

        if (Feature::for($property)->active(Transfers::class)) {
            $transfers = UnitTransfer::query()
                ->where('reservation_unit_id', $stay->id)
                ->with('transferRoute')
                ->get();

            foreach ($transfers as $transfer) {
                $plans[] = $this->transfer($stay, $transfer);
            }
        }

        if (Feature::for($property)->active(Facilities::class)) {
            $bookings = FacilityBooking::query()
                ->where('property_id', $stay->property_id)
                ->where('reservation_id', $stay->reservation_id)
                ->whereIn('status', [FacilityBookingStatus::Tentative->value, FacilityBookingStatus::Confirmed->value])
                ->whereDate('booking_date', '>=', $from)
                ->with('facility')
                ->get();

            foreach ($bookings as $booking) {
                $plans[] = new ConciergePlanData(
                    kind: 'activity',
                    date: $booking->booking_date->toDateString(),
                    time: substr($booking->starts_at, 0, 5).'–'.substr($booking->ends_at, 0, 5),
                    title: $booking->facility->name,
                    detail: $booking->private ? __('Private') : $booking->quantity.' '.$booking->facility->booked_by->unit($booking->quantity),
                );
            }
        }

        usort($plans, fn (ConciergePlanData $a, ConciergePlanData $b): int => [$a->date, $a->time] <=> [$b->date, $b->time]);

        return $plans;
    }

    private function transfer(ReservationUnit $stay, UnitTransfer $transfer): ConciergePlanData
    {
        $isIn = $transfer->direction === TransferDirection::In;
        $place = $transfer->transferRoute->place;

        return new ConciergePlanData(
            kind: 'transfer',
            date: ($isIn ? $stay->arrival_date : $stay->departure_date)->toDateString(),
            time: substr($transfer->pickup_time, 0, 5),
            title: $isIn ? __('Pick-up at :place', ['place' => $place]) : __('Transfer to :place', ['place' => $place]),
            detail: implode(' · ', array_filter([
                $transfer->flight_number === null ? null : __('Flight :number', ['number' => $transfer->flight_number]),
                match ($transfer->mode) {
                    TransferMode::Shared => __('Shared van'),
                    TransferMode::Private => __('Private vehicle'),
                    TransferMode::Included => __('In your package'),
                },
            ])),
        );
    }
}
