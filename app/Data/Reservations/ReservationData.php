<?php

namespace App\Data\Reservations;

use App\Data\Shared\BadgeData;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Spatie\LaravelData\Data;

/**
 * The header and summary card of the reservation page. Expects `booker` and `creator` to be loaded. The figures come
 * from the units that hold inventory, and the dates fall back to all units when none does.
 */
class ReservationData extends Data
{
    public function __construct(
        public int $id,
        public string $reference,
        public string $title,
        public ?string $groupName,
        public bool $isGroup,
        public bool $isClosed,
        public BadgeData $status,
        public ?string $firstArrival,
        public ?string $lastDeparture,
        public int $unitCount,
        public int $roomNights,
        public string $stayTotal,
        public ?string $balance,
        public string $billingMode,
        public string $billingModeLabel,
        public string $source,
        public string $sourceLabel,
        public ?string $channelName,
        public ?string $externalReference,
        public ?string $notes,
        public string $createdOn,
        public ?string $createdBy,
        public ReservationBookerData $booker,
    ) {}

    public static function fromModel(
        Reservation $reservation,
        int $unitCount,
        ?string $firstArrival,
        ?string $lastDeparture,
        int $roomNights,
        string $stayTotal,
        ?string $balance,
        bool $canViewGuest,
    ): self {
        return new self(
            id: $reservation->id,
            reference: $reservation->reference,
            title: $reservation->group_name ?? $reservation->booker->full_name,
            groupName: $reservation->group_name,
            isGroup: $unitCount > 1,
            isClosed: in_array($reservation->status, [ReservationStatus::Cancelled, ReservationStatus::Completed], true),
            status: BadgeData::fromReservationStatus($reservation->status),
            firstArrival: $firstArrival,
            lastDeparture: $lastDeparture,
            unitCount: $unitCount,
            roomNights: $roomNights,
            stayTotal: $stayTotal,
            balance: $balance,
            billingMode: $reservation->billing_mode->value,
            billingModeLabel: $reservation->billing_mode->label(),
            source: $reservation->source->value,
            sourceLabel: $reservation->source->label(),
            channelName: $reservation->channel_name,
            externalReference: $reservation->external_reference,
            notes: $reservation->notes,
            createdOn: $reservation->created_at->toDateString(),
            createdBy: $reservation->creator?->name,
            booker: ReservationBookerData::fromModel($reservation->booker, $canViewGuest),
        );
    }
}
