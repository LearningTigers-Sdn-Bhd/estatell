<?php

namespace App\Services\Reports;

use App\Data\Reports\FacilityBookingRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportSection;
use App\Data\Shared\BadgeData;
use App\Enums\FacilityBookingStatus;
use App\Enums\RemarkKind;
use App\Enums\Tone;
use App\Enums\UnitStatus;
use App\Models\FacilityBooking;
use App\Models\FacilitySlotCrew;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Facilities\ActivityRunSheet;
use App\Services\Guests\GuestParty;
use App\Services\Reservations\ReservationRoomBox;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The facility bookings from `from` to `to`, for the desk and the guides: who comes, how many, and where to find them.
 * Tentative and Confirmed by default; `facilityStatuses` picks others, Cancelled included. Rows go by date, facility
 * (in its settings order) and start time. One section for each facility, date and time, with the quantity of its
 * Tentative and Confirmed bookings against the capacity. See docs/33-facilities/05-timeline-and-report.md#facility-bookings-report.
 */
class FacilityBookingsReport implements HasSections, Report
{
    public function __construct(private ReservationRoomBox $roomBox, private ActivityRunSheet $runSheet, private GuestParty $guestParty) {}

    /**
     * @param  list<int|string>|null  $rowKeys  Booking ids.
     * @return list<FacilityBookingRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $statuses = $filters->facilityStatuses === [] ? [FacilityBookingStatus::Tentative->value, FacilityBookingStatus::Confirmed->value] : $filters->facilityStatuses;
        $bookings = FacilityBooking::query()
            ->select('facility_bookings.*')
            ->join('facilities', 'facilities.id', '=', 'facility_bookings.facility_id')
            ->where('facility_bookings.property_id', $property->id)
            ->whereBetween('facility_bookings.booking_date', [$filters->from, $filters->to])
            ->whereIn('facility_bookings.status', $statuses)
            ->when($filters->facilityIds !== [], fn (Builder $query) => $query->whereIn('facility_bookings.facility_id', $filters->facilityIds))
            ->when($rowKeys !== null, fn (Builder $query) => $query->whereIn('facility_bookings.id', array_map(intval(...), $rowKeys)))
            ->with(['facility', 'folio:id,number', 'event.payerGuest', 'event.payerCompany', 'reservation.booker', 'reservation.units:id,reservation_id,status,adults,children', 'reservation.units.guests.guest', 'reservation.remarks' => fn ($query) => $query->where('kind', RemarkKind::Dietary)])
            ->orderBy('facility_bookings.booking_date')
            ->orderBy('facilities.position')
            ->orderBy('facility_bookings.starts_at')
            ->orderBy('facility_bookings.ends_at')
            ->orderBy('facility_bookings.id')
            ->get();
        $unitIds = $bookings->flatMap(fn (FacilityBooking $booking): array => $booking->reservation === null ? [] : $booking->reservation->units
            ->reject(fn (ReservationUnit $unit): bool => in_array($unit->status, [UnitStatus::Cancelled, UnitStatus::NoShow], true))
            ->modelKeys())->all();
        $rooms = $unitIds === [] ? [] : $this->roomBox->assignmentsForUnits(array_values($unitIds));
        $crews = $this->runSheet->crews($property, CarbonImmutable::parse($filters->from), CarbonImmutable::parse($filters->to));

        return array_values($bookings->map(fn (FacilityBooking $booking): FacilityBookingRow => $this->row($booking, $rooms, $crews))->all());
    }

    /**
     * One section for each facility, date and time, in row order. The subtotal is "20 / 20 people": the quantity of the
     * Tentative and Confirmed bookings against the capacity, in warning colour when it is over.
     *
     * @param  list<FacilityBookingRow>  $rows
     * @return list<ReportSection>
     */
    public function sections(array $rows, ReportFilters $filters): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[$row->section][] = $row;
        }

        return array_values(array_map(function (array $group): ReportSection {
            $first = $group[0];
            $booked = array_sum(array_map(fn (FacilityBookingRow $row): int => $row->counts ? $row->quantity : 0, $group));

            return new ReportSection(
                title: $first->facility.' · '.CarbonImmutable::parse($first->date)->format('j M').' · '.$first->time.($first->crew === null ? '' : ' · '.$first->crew),
                rowKeys: array_map(fn (FacilityBookingRow $row): int => $row->key, $group),
                subtotal: $booked.' / '.$first->capacity.' '.$first->unit,
                tone: $booked > $first->capacity ? Tone::Warning : null,
            );
        }, $groups));
    }

    /**
     * @param  array<int, string>  $rooms  The first night room of each live unit, by unit id.
     * @param  array<string, FacilitySlotCrew>  $crews  The guide and boat of each slot (`ActivityRunSheet::crews()`).
     */
    private function row(FacilityBooking $booking, array $rooms, array $crews): FacilityBookingRow
    {
        $slotCrew = $crews[$booking->booking_date->toDateString().'|'.$booking->facility_id.'|'.$booking->starts_at.'|'.$booking->ends_at] ?? null;
        $crew = $slotCrew === null ? [] : array_values(array_filter([
            $slotCrew->guide === null ? null : __('Guide: :name', ['name' => $slotCrew->guide->name]),
            $slotCrew->boat === null ? null : __('Boat: :name', ['name' => $slotCrew->boat->name]),
        ]));
        $facility = $booking->facility;
        $event = $booking->event;
        $reservation = $booking->reservation;
        $time = substr($booking->starts_at, 0, 5).'–'.substr($booking->ends_at, 0, 5);
        $units = $facility->rate_basis->quantity($booking->starts_at, $booking->ends_at, $booking->quantity, $facility->booked_by, $booking->private);
        $roomList = $reservation === null ? [] : array_values(array_filter(array_map(
            fn (int $unitId): ?string => $rooms[$unitId] ?? null,
            $reservation->units->modelKeys(),
        )));

        return new FacilityBookingRow(
            key: $booking->id,
            date: $booking->booking_date->toDateString(),
            time: $time,
            facility: $facility->name,
            owner: $event->name ?? $reservation->booker->full_name ?? '',
            ref: $event !== null ? $event->number : ($roomList === [] ? (string) $reservation?->reference : implode(', ', $roomList)),
            quantity: $booking->quantity,
            status: BadgeData::fromFacilityBookingStatus($booking->status),
            phone: $event !== null ? ($event->payerGuest->phone ?? $event->payerCompany->contact_phone ?? null) : $reservation?->booker->phone,
            folio: $booking->folio->number,
            amount: $booking->unit_amount === null ? null : (string) BigDecimal::of($booking->unit_amount)->multipliedBy($units)->toScale(2),
            notes: $booking->private ? trim(__('Private').' '.$booking->notes) : $booking->notes,
            dietary: $reservation?->remarks->first()?->body,
            section: $facility->id.'|'.$booking->booking_date->toDateString().'|'.$time,
            capacity: $facility->capacity,
            unit: $facility->booked_by->unit($facility->capacity),
            counts: $booking->status !== FacilityBookingStatus::Cancelled,
            crew: $crew === [] ? null : implode(' · ', $crew),
            party: $reservation === null ? null : $this->guestParty->line(
                $reservation->units->reject(fn (ReservationUnit $unit): bool => in_array($unit->status, [UnitStatus::Cancelled, UnitStatus::NoShow], true)),
                $booking->booking_date,
                $reservation->booker,
            ),
        );
    }
}
