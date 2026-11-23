<?php

namespace App\Data\Facilities;

use App\Data\Shared\BadgeData;
use App\Enums\FacilityBookingStatus;
use App\Models\FacilityBooking;
use Brick\Math\BigDecimal;
use Spatie\LaravelData\Data;

/**
 * One row of an event's Bookings tab, and what the Book facility Sheet needs to edit it. Times are `HH:MM`. `amount`
 * is the price before tax (the unit price times the rate basis quantity), or null when charged by hand. The `can*`
 * flags already combine the status and the user's `facilities.book`.
 */
class FacilityBookingRowData extends Data
{
    public function __construct(
        public int $id,
        public int $facilityId,
        public string $facilityName,
        public string $bookingDate,
        public string $startsAt,
        public string $endsAt,
        public int $quantity,
        public string $capacityUnitLabel,
        public ?string $unitAmount,
        public ?string $amount,
        public string $rateBasisLabel,
        public ?string $notes,
        public BadgeData $status,
        public bool $canEdit,
        public bool $canCancel,
    ) {}

    /**
     * Expects `facility` to be loaded. `$canChange` is false when the user lacks `facilities.book` or the owner takes
     * no bookings.
     */
    public static function fromModel(FacilityBooking $booking, bool $canChange): self
    {
        $facility = $booking->facility;
        $live = $booking->status !== FacilityBookingStatus::Cancelled;
        $units = $facility->rate_basis->quantity($booking->starts_at, $booking->ends_at, $booking->quantity);

        return new self(
            id: $booking->id,
            facilityId: $facility->id,
            facilityName: $facility->name,
            bookingDate: $booking->booking_date->toDateString(),
            startsAt: substr($booking->starts_at, 0, 5),
            endsAt: substr($booking->ends_at, 0, 5),
            quantity: $booking->quantity,
            capacityUnitLabel: $facility->capacity_unit->label(),
            unitAmount: $booking->unit_amount,
            amount: $booking->unit_amount === null ? null : (string) BigDecimal::of($booking->unit_amount)->multipliedBy($units)->toScale(2),
            rateBasisLabel: $facility->rate_basis->label(),
            notes: $booking->notes,
            status: BadgeData::fromFacilityBookingStatus($booking->status),
            canEdit: $canChange && $live,
            canCancel: $canChange && $live,
        );
    }
}
