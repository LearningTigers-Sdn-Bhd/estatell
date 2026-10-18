<?php

namespace App\Data\Portal;

use App\Data\Shared\BadgeData;
use App\Enums\PortalCancelMode;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use Brick\Math\BigDecimal;
use Spatie\LaravelData\Data;

/**
 * One booking of the company as the portal shows it. `cancelMode` says whether the page offers Cancel (`free`),
 * Request cancellation (`request`) or neither (`closed`); `freeUntil` is the last day to cancel free. A Suspended
 * company sees the booking but no action: `canEdit` and `cancelMode` are already settled for that.
 */
class PortalBookingData extends Data
{
    /**
     * @param  list<PortalBookingUnitData>  $units
     * @param  list<PortalGuestData>  $guests
     */
    public function __construct(
        public int $id,
        public string $reference,
        public ?string $externalReference,
        public string $title,
        public string $bookerName,
        public string $firstArrival,
        public string $lastDeparture,
        public string $total,
        public BadgeData $status,
        public array $units,
        public array $guests,
        public bool $canEditGuests,
        public PortalCancelMode $cancelMode,
        public ?string $freeUntil,
        public bool $cancellationRequested,
        public ?string $cancellationRequestedAt,
    ) {}

    /**
     * Load `booker.documents`, `units` with their `roomType`, `ratePlan`, `nights`, `dayUse`, `guests.guest.documents` and
     * `addOns.chargeItem` first.
     */
    public static function fromReservation(Reservation $reservation, bool $canEditGuests, PortalCancelMode $cancelMode, ?string $freeUntil): self
    {
        $units = $reservation->units->sortBy('id')->values();
        $live = $units->reject(fn (ReservationUnit $unit): bool => in_array($unit->status->value, ['cancelled', 'no_show'], true));
        $unitData = $units->map(fn (ReservationUnit $unit): PortalBookingUnitData => PortalBookingUnitData::fromUnit($unit));
        $total = $live->reduce(fn (BigDecimal $sum, ReservationUnit $unit): BigDecimal => $sum->plus($unitData->firstWhere('id', $unit->id)->total), BigDecimal::zero());

        $guests = $units->flatMap(fn (ReservationUnit $unit) => $unit->guests->pluck('guest'))->push($reservation->booker)->unique('id');

        return new self(
            id: $reservation->id,
            reference: $reservation->reference,
            externalReference: $reservation->external_reference,
            title: $reservation->group_name ?? $reservation->booker->full_name,
            bookerName: $reservation->booker->full_name,
            firstArrival: $units->min(fn (ReservationUnit $unit): string => $unit->arrival_date->toDateString()),
            lastDeparture: $units->max(fn (ReservationUnit $unit): string => $unit->departure_date->toDateString()),
            total: (string) $total->toScale(2),
            status: PortalBookingRowData::status($reservation),
            units: array_values($unitData->all()),
            guests: array_values($guests->map(fn ($guest): PortalGuestData => PortalGuestData::fromGuest($guest))->all()),
            canEditGuests: $canEditGuests,
            cancelMode: $cancelMode,
            freeUntil: $freeUntil,
            cancellationRequested: $reservation->cancellation_requested_at !== null,
            cancellationRequestedAt: $reservation->cancellation_requested_at?->toDateString(),
        );
    }
}
